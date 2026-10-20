<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Budget\Application\ReadPeriodCashIncome;
use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Reporting\Application\ResolveMetricPolicy;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;
use App\Module\Transactions\Application\TransactionCursor;
use App\Module\Transactions\Infrastructure\Persistence\DbalBookedMovementReader;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NFR-001 backend budgets. Not part of the default PHPUnit suite: it lives outside `tests/`, so the normal
 * PR gate never pays for a 100 000-row seed. Run it with `make perf`, or for the timing gate:
 *
 *   PERF_ASSERT=1 make perf
 *
 * The data set is {@see SyntheticVolumeSeeder}: 100 000 live transactions over the last 15 years (about 560 a
 * month, one month pinned at the monthly read bound, 2000). The monthly report budget applies to a representative month, the one closest
 * to the average; the heavy month is measured and printed but not asserted. Each endpoint is
 * called through the HTTP kernel as the signed-in owner, so a timing includes routing, authentication,
 * workspace authorization, the queries and the JSON serialization. The kernel is not kept warm between requests
 * (the test client reboots it), which is the conservative reading for a PHP-FPM style deployment.
 *
 * Timings only fail the run with PERF_ASSERT=1 (they are not stable on shared CI runners). The plan shape of the
 * transaction search and of the monthly read is asserted every time: "an index scan was used" is stable.
 */
final class ReportBudgetPerformanceTest extends WebTestCase
{
    private const int TRANSACTIONS = 100_000;
    private const int YEARS = 15;
    private const int WARM_UP_RUNS = 2;
    private const int TIMED_RUNS = 20;
    private const float MONTHLY_REPORT_P95_BUDGET_MS = 300.0;
    private const int DEEP_PAGE_OFFSET = 50_000;
    private const string TIMEZONE = 'Europe/Paris';
    /** A year-long income read, and a read of as many movements as its bound allows, must stay well under a 128 MB PHP limit. */
    private const int MEMORY_BUDGET_BYTES = 64 * 1024 * 1024;

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient(['debug' => false]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($this->connection);
        $this->fixture->reset();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        // The 100 000-row load and delete leaves bloated tables and indexes behind, which skews the plans other
        // performance tests assert on this same database; rewriting the now empty tables returns them to size.
        foreach (['transaction_transactions', 'transaction_splits', 'account_balance_snapshots'] as $table) {
            $this->connection->executeStatement('VACUUM FULL '.$table);
        }
        parent::tearDown();
    }

    public function testEveryReportAnswersWithinItsBudgetOnOneHundredThousandTransactionsOverFifteenYears(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);

        $today = new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE));
        $seedStart = hrtime(true);
        $summary = (new SyntheticVolumeSeeder($this->connection))->seed(
            WorkspaceFixture::OWN_WORKSPACE,
            WorkspaceFixture::OWNER_ID,
            $today,
            self::TRANSACTIONS,
            self::YEARS,
            ReadMonthlyTransactionFacts::MAX_TRANSACTIONS,
        );
        $seedSeconds = (hrtime(true) - $seedStart) / 1e9;
        self::assertSame(self::TRANSACTIONS, $summary->transactions);
        self::assertLessThanOrEqual(ReadMonthlyTransactionFacts::MAX_TRANSACTIONS, $summary->busiestMonth);
        self::assertNotSame('', $summary->heavyMonth);
        $representativeMonth = self::representativeMonth($summary, $today);

        $plans = $this->assertQueryPlansUseIndexes($representativeMonth, $summary->heavyMonth);
        // Sparse anchors force both current balances and historical points through movement windows.
        $this->connection->executeStatement(
            'DELETE FROM account_balance_snapshots s USING account_financial_accounts a '
            .'WHERE s.workspace_id = :workspace_id AND a.workspace_id = :workspace_id AND s.account_id = a.id '
            ."AND a.valuation_mode = 'TRANSACTIONS' AND (EXTRACT(MONTH FROM s.as_of)::int % 2 = 0 OR s.as_of >= :current_month)",
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'current_month' => $today->format('Y-m-01')],
        );
        $this->signIn();

        $year = (int) $today->format('Y');
        $deepCursor = $this->deepCursor();
        // Each endpoint carries a sanity check, so a fast empty answer cannot pass for a fast report.
        $endpoints = [
            'monthly report' => ['/api/v1/reports/monthly?month='.$representativeMonth, static function (array $body): void {
                self::assertSame('COMPLETE', $body['state']);
            }],
            'monthly report (at bound, 2000)' => ['/api/v1/reports/monthly?month='.$summary->heavyMonth, static function (array $body): void {
                self::assertSame('COMPLETE', $body['state']);
            }],
            'annual report' => ['/api/v1/reports/annual/'.($year - 1), static function (array $body): void {
                self::assertCount(12, self::items($body['rows']));
            }],
            'multi-year report' => [sprintf('/api/v1/reports/multi-year?from=%d&to=%d', $year - self::YEARS + 1, $year), static function (array $body): void {
                self::assertCount(self::YEARS, self::items($body['years']));
            }],
            'monthly recap' => ['/api/v1/reports/monthly/recap?month='.$representativeMonth, static function (array $body): void {
                self::assertCount(12, self::items($body['accounts']));
            }],
            'account list' => ['/api/v1/accounts?pageSize=100', static function (array $body): void {
                self::assertCount(12, self::items($body['items']));
                self::assertSame(12, $body['total']);
                self::assertContains('SNAPSHOT_PLUS_MOVEMENTS', array_column(array_column(self::items($body['items']), 'valuation'), 'basis'));
            }],
            'net worth' => ['/api/v1/net-worth', static function (array $body): void {
                self::assertNotEmpty($body);
            }],
            'net worth history' => ['/api/v1/net-worth/history?months=60', static function (array $body): void {
                self::assertNotEmpty(self::items($body['points']));
            }],
            'transactions first page' => ['/api/v1/transactions', static function (array $body): void {
                self::assertCount(50, self::items($body['items']));
            }],
            'transactions deep page' => ['/api/v1/transactions?cursor='.$deepCursor, static function (array $body): void {
                self::assertCount(50, self::items($body['items']));
            }],
        ];

        $results = [];
        foreach ($endpoints as $name => [$uri, $check]) {
            $results[$name] = $this->measure($name, $uri, $check);
        }

        $memory = $this->measureBudgetIncomeMemory($summary);

        fwrite(STDOUT, "\n".$this->render($summary, $representativeMonth, $seedSeconds, $results, $plans, $memory));

        if ('1' === getenv('PERF_ASSERT')) {
            self::assertLessThanOrEqual(self::MONTHLY_REPORT_P95_BUDGET_MS * 1.2, $results['monthly recap']['p95'], 'Monthly recap p95 budget: 360 ms (monthly baseline plus 20 percent).');
            self::assertLessThan(150.0, $results['account list']['p95'], 'Account list p95 budget: 150 ms.');
            self::assertLessThan(800.0, $results['net worth history']['p95'], '60-point net worth history p95 budget: 800 ms.');
            self::assertLessThanOrEqual(
                self::MONTHLY_REPORT_P95_BUDGET_MS,
                $results['monthly report']['p95'],
                sprintf('The monthly report p95 is %.1f ms; the budget is %.0f ms.', $results['monthly report']['p95'], self::MONTHLY_REPORT_P95_BUDGET_MS),
            );
        }
    }

    /**
     * Peak memory added by a YEAR budget period income read, on the heaviest seeded year, and by a read of as many
     * movements as the bound allows (24 000 plus the one that proves it is exceeded). Memory is deterministic, so
     * this always asserts, unlike the timings.
     *
     * @return array<string, array{rows: int, bytes: int}>
     */
    private function measureBudgetIncomeMemory(SyntheticVolumeSummary $summary): array
    {
        $perYear = [];
        foreach ($summary->rowsPerMonth as $month => $rows) {
            $perYear[substr($month, 0, 4)] = ($perYear[substr($month, 0, 4)] ?? 0) + $rows;
        }
        arsort($perYear);
        $year = (int) array_key_first($perYear);

        $container = self::getContainer();
        $accounts = $container->get(AccountRepository::class);
        $movements = new DbalBookedMovementReader($this->connection);
        $timezones = $container->get(WorkspaceTimezoneReader::class);
        $policy = $container->get(ResolveMetricPolicy::class);
        self::assertInstanceOf(AccountRepository::class, $accounts);
        self::assertInstanceOf(WorkspaceTimezoneReader::class, $timezones);
        self::assertInstanceOf(ResolveMetricPolicy::class, $policy);
        $workspace = WorkspaceScope::fromString(WorkspaceFixture::OWN_WORKSPACE);
        $read = new ReadPeriodCashIncome($accounts, $movements, $timezones, $policy);

        $measured = [];
        $measured['year budget income, heaviest year '.$year] = ['rows' => $perYear[(string) $year], 'bytes' => $this->memoryAdded(
            static fn (): mixed => self::assertNotNull($read($workspace, BudgetPeriod::year($year))->value),
        )];
        $bound = ReadPeriodCashIncome::MAX_TRANSACTIONS + 1;
        $measured['movement read at the bound'] = ['rows' => $bound, 'bytes' => $this->memoryAdded(
            static function () use ($movements, $workspace, $bound): void {
                self::assertCount($bound, $movements->between($workspace, new \DateTimeImmutable('2000-01-01'), new \DateTimeImmutable('2099-12-31'), $bound));
            },
        )];

        foreach ($measured as $label => $result) {
            self::assertLessThan(self::MEMORY_BUDGET_BYTES, $result['bytes'], sprintf('%s added %.1f MB.', $label, $result['bytes'] / 1e6));
        }

        return $measured;
    }

    /** @param callable(): mixed $work */
    private function memoryAdded(callable $work): int
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        // Bytes in use, not the allocator's 2 MB chunks: chunks already held from earlier work would hide the read.
        $before = memory_get_usage();
        $work();

        return memory_get_peak_usage() - $before;
    }

    /**
     * @param callable(array<string, mixed>): void $check
     *
     * @return array{cold: float, p50: float, p95: float}
     */
    private function measure(string $name, string $uri, callable $check): array
    {
        $cold = $this->get($uri, $name, $check);
        for ($run = 1; $run < self::WARM_UP_RUNS; ++$run) {
            $this->get($uri, $name, $check);
        }
        $samples = [];
        for ($run = 0; $run < self::TIMED_RUNS; ++$run) {
            $samples[] = $this->get($uri, $name, $check);
        }
        $statistics = new LatencyStatistics($samples);

        return ['cold' => $cold, 'p50' => $statistics->percentile(50), 'p95' => $statistics->percentile(95)];
    }

    /** @param callable(array<string, mixed>): void $check */
    private function get(string $uri, string $name, callable $check): float
    {
        $start = hrtime(true);
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
        $elapsed = (hrtime(true) - $start) / 1e6;

        $status = $this->client->getResponse()->getStatusCode();
        self::assertSame(200, $status, sprintf('%s answered %d.', $name, $status));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $check($body);

        return $elapsed;
    }

    private function signIn(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** The keyset cursor the API hands out 50 000 live rows into the default listing. */
    private function deepCursor(): string
    {
        $row = $this->deepRow("state IN ('PENDING', 'BOOKED')");
        $watermark = (new DbalTransactionRepository($this->connection))->watermark(WorkspaceScope::fromString(WorkspaceFixture::OWN_WORKSPACE));
        self::assertNotNull($watermark);

        return (new TransactionCursor(new \DateTimeImmutable((string) $row['booked_on']), (string) $row['id'], $watermark))->encode();
    }

    /** @return array{booked_on: string, id: string} */
    private function deepRow(string $stateFilter): array
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT booked_on, id FROM transaction_transactions WHERE workspace_id = :workspace_id AND %s ORDER BY booked_on DESC, id DESC OFFSET :offset LIMIT 1', $stateFilter),
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'offset' => self::DEEP_PAGE_OFFSET],
            ['offset' => ParameterType::INTEGER],
        );
        self::assertIsArray($row);

        return ['booked_on' => (string) $row['booked_on'], 'id' => (string) $row['id']];
    }

    /** @return array<string, string> the plan of each query, for the report */
    private function assertQueryPlansUseIndexes(string $representativeMonth, string $heavyMonth): array
    {
        $types = ['states' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER];
        $base = ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'states' => ['PENDING', 'BOOKED']];
        $cursor = $this->deepRow("state IN ('PENDING', 'BOOKED')");
        $monthlyRead = fn (string $month): string => $this->explain(
            'SELECT t.id FROM transaction_transactions t WHERE t.workspace_id = :workspace_id AND t.state IN (:states)
             AND t.booked_on >= :from_date AND t.booked_on <= :to_date ORDER BY t.booked_on DESC, t.id DESC LIMIT :limit',
            $base + [
                'limit' => ReadMonthlyTransactionFacts::MAX_TRANSACTIONS + 1,
                'from_date' => $month.'-01',
                'to_date' => (new \DateTimeImmutable($month.'-01'))->format('Y-m-t'),
            ],
            $types,
        );
        $plans = [
            'first page' => $this->explain(
                'SELECT t.id FROM transaction_transactions t WHERE t.workspace_id = :workspace_id AND t.state IN (:states)
                 ORDER BY t.booked_on DESC, t.id DESC LIMIT :limit',
                $base + ['limit' => 51],
                $types,
            ),
            'deep page' => $this->explain(
                'SELECT t.id FROM transaction_transactions t WHERE t.workspace_id = :workspace_id AND t.state IN (:states)
                 AND (t.booked_on, t.id) < (:booked_on, :cursor_id) ORDER BY t.booked_on DESC, t.id DESC LIMIT :limit',
                $base + ['limit' => 51, 'booked_on' => $cursor['booked_on'], 'cursor_id' => $cursor['id']],
                $types,
            ),
            'monthly read' => $monthlyRead($representativeMonth),
            'monthly read (at bound, 2000)' => $monthlyRead($heavyMonth),
        ];

        foreach ($plans as $label => $plan) {
            self::assertStringNotContainsString('Seq Scan on transaction_transactions', $plan, $label.' must not fall back to a sequential scan.');
            self::assertStringContainsString('transaction_transactions_listing_index', $plan, $label.' must use the listing index.');
        }

        return $plans;
    }

    /**
     * The closed month, heavy one excluded, whose volume is closest to the average of the others: the "average
     * month" a monthly report budget is meant for, chosen from the data rather than by the calendar.
     */
    private static function representativeMonth(SyntheticVolumeSummary $summary, \DateTimeImmutable $today): string
    {
        $candidates = array_diff_key($summary->rowsPerMonth, [$summary->heavyMonth => 1, $today->format('Y-m') => 1]);
        $average = array_sum($candidates) / count($candidates);
        $best = '';
        $distance = INF;
        foreach ($candidates as $month => $rows) {
            if (abs($rows - $average) < $distance) {
                $best = (string) $month;
                $distance = abs($rows - $average);
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed>                                $parameters
     * @param array<string, ArrayParameterType|ParameterType|int> $types
     */
    private function explain(string $sql, array $parameters, array $types): string
    {
        $rows = $this->connection->fetchAllAssociative('EXPLAIN (ANALYZE, BUFFERS) '.$sql, $parameters, $types);

        return implode("\n", array_map(static fn (array $row): string => (string) $row['QUERY PLAN'], $rows));
    }

    /**
     * @param array<string, array{cold: float, p50: float, p95: float}> $results
     * @param array<string, string>                                     $plans
     * @param array<string, array{rows: int, bytes: int}>               $memory
     */
    private function render(SyntheticVolumeSummary $summary, string $representativeMonth, float $seedSeconds, array $results, array $plans, array $memory): string
    {
        $lines = [
            sprintf(
                'PERF NFR-001: %d live transactions, %d months (%s to %s), seeded in %.1f s; representative month %s (%d rows), heavy month %s (%d rows, at the monthly read bound)',
                $summary->transactions,
                $summary->months,
                $summary->firstMonth,
                $summary->lastMonth,
                $seedSeconds,
                $representativeMonth,
                $summary->rowsPerMonth[$representativeMonth],
                $summary->heavyMonth,
                $summary->busiestMonth,
            ),
            sprintf('%-26s %10s %10s %10s', 'endpoint (ms)', 'cold', 'p50', 'p95'),
        ];
        foreach ($results as $name => $result) {
            $lines[] = sprintf('%-26s %10.1f %10.1f %10.1f', $name, $result['cold'], $result['p50'], $result['p95']);
        }
        foreach ($memory as $label => $result) {
            $lines[] = sprintf('memory: %-36s %6d rows %7.1f MB peak added', $label, $result['rows'], $result['bytes'] / 1e6);
        }
        foreach ($plans as $label => $plan) {
            $lines[] = '--- plan: '.$label."\n".$plan;
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array<array-key, mixed> */
    private static function items(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
