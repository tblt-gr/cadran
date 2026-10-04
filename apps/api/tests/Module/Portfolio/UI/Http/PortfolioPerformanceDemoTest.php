<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Demo\Application\SeedDemoData;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PortfolioPerformanceDemoTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        // Debug mode would keep every query backtrace of a full year of seeding in memory.
        $this->client = self::createClient(['debug' => false]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);

        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);
        $seed(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->send('POST', '/api/v1/session', ['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testTheDemoAccountShowsCalculableStaleWithdrawalAndOpenPartialMonths(): void
    {
        $plain = $this->month("SELECT to_char(MIN(valued_on), 'YYYY-MM') FROM portfolio_valuations WHERE workspace_id = ? AND account_id = ? AND superseded_at IS NULL AND completeness = 'COMPLETE' AND detail = 'TOTAL' AND valued_on > (SELECT MIN(valued_on) FROM portfolio_valuations WHERE workspace_id = ? AND account_id = ? AND superseded_at IS NULL)");
        $plainBody = $this->performance($plain);
        $plainGain = self::map($plainBody['gain']);
        $money = self::map($plainBody['moneyWeighted']);
        self::assertTrue($money['shortPeriod']);
        self::assertNotNull($money['periodRate']);
        self::assertSame($money['annualizedRate'], $money['value']);
        self::assertNotNull($plainGain['value']);
        self::assertContains($plainGain['quality'], ['EXACT', 'DEGRADED']);

        $stale = $this->month("SELECT to_char(MAX(v.valued_on), 'YYYY-MM') FROM portfolio_valuations v JOIN portfolio_position_snapshots s ON s.valuation_id = v.id WHERE v.workspace_id = ? AND v.account_id = ? AND v.superseded_at IS NULL AND v.completeness = 'COMPLETE' AND s.price_quality = 'STALE'");
        $stalePerformance = $this->performance($stale);
        $staleGain = self::map($stalePerformance['gain']);
        $staleDietz = self::map($stalePerformance['modifiedDietz']);
        self::assertNotNull($staleDietz['value']);
        self::assertSame('DEGRADED', $staleDietz['quality']);
        self::assertContains('STALE_PRICES', array_column(self::list($staleDietz['reasons']), 'code'));
        self::assertNotNull($staleGain['value']);
        self::assertContains('STALE_PRICES', array_column(self::list($staleGain['reasons']), 'code'));
        self::assertSame('DEGRADED', $staleGain['quality']);

        $withdrawal = $this->month("SELECT to_char(MAX(trade_date), 'YYYY-MM') FROM portfolio_operations WHERE workspace_id = ? AND account_id = ? AND state = 'ACTIVE' AND operation_type = 'WITHDRAWAL'");
        $withdrawalBody = $this->performance($withdrawal);
        self::assertContains('WITHDRAWAL', array_column(self::list($withdrawalBody['flows']), 'type'));
        self::assertNotNull(self::map($withdrawalBody['gain'])['value']);

        $open = $this->month("SELECT to_char(MAX(valued_on), 'YYYY-MM') FROM portfolio_valuations WHERE workspace_id = ? AND account_id = ? AND superseded_at IS NULL AND completeness = 'PARTIAL'");
        $partial = self::map($this->performance($open)['gain']);
        self::assertNull($partial['value']);
        self::assertSame('NOT_CALCULABLE', $partial['quality']);
        self::assertSame('END_VALUATION_PARTIAL', self::map(self::list($partial['reasons'])[0])['code']);
    }

    public function testDemoShowsExactLatestClosedMonthAndMissingEarlierFlowValuations(): void
    {
        $latest = $this->month("SELECT to_char(MAX(valued_on), 'YYYY-MM') FROM portfolio_valuations WHERE workspace_id = ? AND account_id = ? AND superseded_at IS NULL AND completeness = 'COMPLETE'");
        $exact = self::map($this->performance($latest)['timeWeighted']);
        self::assertNotNull($exact['value'], json_encode($exact['reasons'], JSON_THROW_ON_ERROR));
        self::assertSame('EXACT', $exact['quality']);
        $previous = (new \DateTimeImmutable($latest.'-01'))->modify('-1 month')->format('Y-m');
        $missing = self::map($this->performance($previous)['timeWeighted']);
        self::assertNull($missing['value']);
        self::assertContains('MISSING_FLOW_VALUATION', array_column(self::list($missing['reasons']), 'code'));
        self::assertSame(2, $missing['missingCount'], 'The preceding stale anchor and the missing deposit-day valuation are both counted.');
        $missingReasons = array_values(array_filter(self::list($missing['reasons']), static fn (array $reason): bool => 'MISSING_FLOW_VALUATION' === $reason['code']));
        self::assertSame([$previous.'-07'], $missingReasons[0]['dates']);
    }

    public function testTheDemoYearDepositsAndWithdrawalsEqualTheCashTotalsBetweenItsAnchors(): void
    {
        $year = (int) $this->month("SELECT to_char(MAX(valued_on), 'YYYY') FROM portfolio_valuations WHERE workspace_id = ? AND account_id = ? AND superseded_at IS NULL AND completeness = 'COMPLETE'");
        $account = $this->account();

        $this->client->request('GET', '/api/v1/accounts/'.$account.'/portfolio-performance/annual?year='.$year, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $annual = self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $line = self::map($annual['yearTotal']);
        self::assertIsString($line['from']);
        self::assertIsString($line['to']);
        $from = (new \DateTimeImmutable($line['from']))->modify('+1 day')->format('Y-m-d');

        $this->client->request('GET', '/api/v1/accounts/'.$account.'/portfolio-cash?from='.$from.'&to='.$line['to'], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $totals = self::map(self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR))['totals']);

        foreach (['deposits', 'withdrawals'] as $key) {
            self::assertIsString($line[$key]);
            self::assertSame(0, ExactDecimal::compareForResponse($line[$key], self::text(self::map($totals[$key])['value'])), $key);
        }
        $months = self::list($annual['months']);
        self::assertCount(12, $months);
        self::assertContains('CALCULATED', array_column($months, 'status'));
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function account(): string
    {
        $id = $this->connection->fetchOne("SELECT id FROM account_financial_accounts WHERE workspace_id = ? AND valuation_mode = 'PORTFOLIO' ORDER BY label LIMIT 1", [WorkspaceFixture::OWN_WORKSPACE]);
        self::assertIsString($id);

        return $id;
    }

    /** A `YYYY-MM` month picked by a query bound to the workspace and the demo portfolio account, once per `?` pair. */
    private function month(string $sql): string
    {
        $pairs = substr_count($sql, '?') / 2;
        $parameters = [];
        for ($index = 0; $index < $pairs; ++$index) {
            array_push($parameters, WorkspaceFixture::OWN_WORKSPACE, $this->account());
        }
        $month = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($month);

        return $month;
    }

    /** @return array<string, mixed> */
    private function performance(string $month): array
    {
        $this->client->request('GET', '/api/v1/accounts/'.$this->account().'/portfolio-performance?scope=MONTH&period='.$month, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $typed = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $typed[$key] = $item;
        }

        return $typed;
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value): array
    {
        self::assertIsList($value);

        return array_map(self::map(...), $value);
    }
}
