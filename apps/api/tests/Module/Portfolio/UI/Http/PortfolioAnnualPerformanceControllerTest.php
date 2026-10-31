<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class PortfolioAnnualPerformanceControllerTest extends WebTestCase
{
    private const string ACCOUNT = PortfolioFixture::ACCOUNT;
    private const string ETF = PortfolioFixture::ETF;
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d7';
    private const string SNAPSHOTS_ACCOUNT = '00000000-0000-7000-8000-0000000000d8';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        // One container for the whole test, so the frozen workspace date reaches every request.
        $this->client->disableReboot();
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-04-30T12:00:00+00:00'));
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

        $portfolio = new PortfolioFixture($connection);
        $portfolio->account();
        $portfolio->account(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $portfolio->account(self::SNAPSHOTS_ACCOUNT, label: 'Assurance-vie', valuationMode: 'SNAPSHOTS');
        $portfolio->instrument(self::ETF, name: 'ETF Monde Démo', isin: 'IE00B4L5Y983');

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

    public function testEveryMonthLineEqualsTheSinglePeriodEndpoint(): void
    {
        $this->seedThroughApril();

        $annual = $this->annual(2026);

        self::assertSame(['PERF-1', 'NET_OF_FEES_AND_TAXES', ['from' => 2026, 'to' => 2026, 'years' => [2026]]], [$annual['methodVersion'], $annual['costPolicy'], $annual['availableYears']]);
        $months = self::lines($annual['months']);
        self::assertCount(12, $months);
        foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $index => $label) {
            self::assertSame($label, $months[$index]['month']);
            self::assertEquals($this->expectedLine($this->single('MONTH', $label)), self::without($months[$index], ['month', 'status', 'endCash', 'modifiedDietzIndex', 'deposits', 'withdrawals']), $label);
        }
        self::assertSame('NOT_CALCULABLE', $months[0]['status'], 'January has no valuation');
        self::assertNull($months[0]['gain']);
        self::assertSame(['CALCULATED', 'CALCULATED', 'CALCULATED'], [$months[1]['status'], $months[2]['status'], $months[3]['status']]);
        self::assertEquals($this->expectedLine($this->single('YEAR', '2026')), self::without(self::map($annual['yearTotal']), ['deposits', 'withdrawals']));
        self::assertEquals($this->expectedLine($this->single('INCEPTION', null)), self::without(self::map($annual['inception']), ['deposits', 'withdrawals']));
    }

    public function testTheReferenceMonthShowsItsFlowsAndEndCash(): void
    {
        $this->seedThroughApril();

        $march = self::lines($this->annual(2026)['months'])[2];

        self::assertSame(['1000.00', '200.00', '800.00', '10000.00', '11050.00', '1861.00'], [$march['deposits'], $march['withdrawals'], $march['netFlows'], $march['startValue'], $march['endValue'], $march['endCash']]);
        self::assertSame('250.00', $march['gain']);
        self::assertSame('0.023499', self::map($march['modifiedDietz'])['value']);
    }

    public function testTheMonthlyComponentsAddUpToTheYearWhenEveryMonthIsCalculable(): void
    {
        $this->connection->executeStatement('UPDATE account_financial_accounts SET opened_on = ? WHERE id = ?', ['2026-02-01', self::ACCOUNT]);
        $this->seedReferenceMonths();
        $this->recordPositions('2026-04-30', [['instrumentId' => self::ETF, 'price' => '103.00', 'priceDate' => '2026-04-30']]);

        $annual = $this->annual(2026);
        $months = self::lines($annual['months']);
        $year = self::map($annual['yearTotal']);

        self::assertSame(['BEFORE_OPENING', 'CALCULATED', 'CALCULATED', 'CALCULATED', 'FUTURE'], [$months[0]['status'], $months[1]['status'], $months[2]['status'], $months[3]['status'], $months[4]['status']]);
        $calculated = array_slice($months, 1, 3);
        self::assertSame(['10798.00', '200.00'], [$year['deposits'], $year['withdrawals']]);
        foreach (['gain', 'deposits', 'withdrawals', 'netFlows', 'realized', 'unrealizedChange', 'income', 'fees', 'taxes'] as $component) {
            self::assertNotNull($year[$component], $component);
            self::assertSame($year[$component], $this->sum(array_map(static fn (array $month): string => self::text($month[$component]), $calculated)), 'sum of '.$component);
        }
    }

    public function testTheYearReturnsAreChainsOfTheMonthsNeverTheirSumNorAverage(): void
    {
        $this->seedThroughApril();

        $annual = $this->annual(2026);
        $year = self::map($annual['yearTotal']);

        self::assertSame('0.050112', self::map($year['modifiedDietz'])['value']);
        $months = self::lines($annual['months']);
        self::assertSame(['0.021380', '0.023499', '0.004525'], array_map(static fn (array $month): ?string => self::nullableText(self::map($month['modifiedDietz'])['value']), array_slice($months, 1, 3)));
        self::assertEquals(
            $this->expectedMoneyWeighted(self::map($this->single('YEAR', '2026')['moneyWeighted'])),
            $year['moneyWeighted'],
            'the year MWR is solved on the year flows, not derived from the months',
        );
    }

    public function testTheIndexUsesTheUnroundedMonthlyRate(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-02-01', 'grossAmount' => $this->eur('10100.00')]);
        $this->recordTotal('2026-02-28', '10100.00');
        $this->recordTotal('2026-03-31', '10100.00');
        $this->recordTotal('2026-04-30', '10100.50');

        $april = self::lines($this->annual(2026)['months'])[3];

        self::assertSame('0.000050', self::map($april['modifiedDietz'])['value']);
        self::assertSame('100.00', $april['modifiedDietzIndex']);
    }

    public function testTheChainedIndexStartsAtTheFirstActiveMonthAndFollowsTheChain(): void
    {
        $this->seedThroughApril();

        $indexes = array_map(static fn (array $month): ?string => self::nullableText($month['modifiedDietzIndex']), self::lines($this->annual(2026)['months']));

        // January has no operation yet; 100 x 1.021380 = 102.14 ; x 1.023499 = 104.54 ; x 1.004525 = 105.01.
        self::assertSame([null, '102.14', '104.54', '105.01', null, null, null, null, null, null, null, null], $indexes);
    }

    public function testTheIndexStopsAtTheFirstNonCalculableMonthWithItsReason(): void
    {
        $this->seedThroughApril();
        $this->connection->executeStatement("DELETE FROM portfolio_position_snapshots WHERE valuation_id IN (SELECT id FROM portfolio_valuations WHERE valued_on = '2026-03-31')");
        $this->connection->executeStatement("DELETE FROM portfolio_valuations WHERE valued_on = '2026-03-31'");

        $annual = $this->annual(2026);
        $months = self::lines($annual['months']);

        self::assertSame(['102.14', null, null], [$months[1]['modifiedDietzIndex'], $months[2]['modifiedDietzIndex'], $months[3]['modifiedDietzIndex']]);
        self::assertSame(['CALCULATED', 'NOT_CALCULABLE', 'NOT_CALCULABLE'], [$months[1]['status'], $months[2]['status'], $months[3]['status']]);
        self::assertSame('NO_END_VALUATION', self::map(self::list($months[2]['gainReasons'])[0])['code']);
        $year = self::map($annual['yearTotal']);
        self::assertEquals($this->expectedLine($this->single('YEAR', '2026')), self::without($year, ['deposits', 'withdrawals']), 'no partial sum is shown as the year');
        self::assertNull(self::map($year['modifiedDietz'])['value']);
    }

    public function testAnAccountWithoutAnyValuationNeverShowsAZero(): void
    {
        $annual = $this->annual(2026);

        foreach ([...self::lines($annual['months']), self::map($annual['yearTotal']), self::map($annual['inception'])] as $line) {
            if (in_array($line['status'] ?? null, ['FUTURE', 'BEFORE_OPENING'], true)) {
                continue;
            }
            self::assertNull($line['gain']);
            self::assertNull($line['modifiedDietzIndex'] ?? null);
            self::assertNull(self::map($line['modifiedDietz'])['value']);
        }
    }

    public function testAMonthBeforeTheOpeningAndAFutureMonthCarryOnlyTheirStatus(): void
    {
        $this->connection->executeStatement('UPDATE account_financial_accounts SET opened_on = ? WHERE id = ?', ['2026-03-01', self::ACCOUNT]);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-10', 'grossAmount' => $this->eur('1000.00')]);
        $this->recordTotal('2026-03-31', '1010.00');

        $months = self::lines($this->annual(2026)['months']);

        self::assertSame(['month' => '2026-01', 'status' => 'BEFORE_OPENING'], $months[0]);
        self::assertSame(['month' => '2026-02', 'status' => 'BEFORE_OPENING'], $months[1]);
        self::assertSame(['month' => '2026-05', 'status' => 'FUTURE'], $months[4]);
        self::assertSame(['month' => '2026-12', 'status' => 'FUTURE'], $months[11]);
        self::assertSame(['CALCULATED', '10.00'], [$months[2]['status'], $months[2]['gain']]);
        self::assertNotNull($months[2]['modifiedDietzIndex'], 'the chain starts at the opening month');
    }

    public function testYearBoundsComeFromTheOpeningYearAndTheWorkspaceYear(): void
    {
        $this->connection->executeStatement('UPDATE account_financial_accounts SET opened_on = ? WHERE id = ?', ['2024-06-15', self::ACCOUNT]);

        self::assertSame(['from' => 2024, 'to' => 2026, 'years' => [2026]], $this->annual(2024)['availableYears']);
        self::assertSame(['BEFORE_OPENING', 'NOT_CALCULABLE'], [self::lines($this->annual(2024)['months'])[4]['status'], self::lines($this->annual(2024)['months'])[5]['status']]);
        foreach (['2023', '2027', 'abc', '26', '2026-01', ''] as $year) {
            $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance/annual?year='.$year);
            self::assertResponseStatusCodeSame(422, $year);
            self::assertSame('invalid_performance_period', $this->decode()['code'], $year);
        }
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance/annual');
        self::assertResponseStatusCodeSame(422);
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance/annual?year[]=2026');
        self::assertResponseStatusCodeSame(422);
    }

    public function testAYearWithNoOperationIsLeftOutOfTheSelector(): void
    {
        $this->connection->executeStatement('UPDATE account_financial_accounts SET opened_on = ? WHERE id = ?', ['2024-06-15', self::ACCOUNT]);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2024-07-01', 'grossAmount' => $this->eur('1000.00')]);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-02-01', 'grossAmount' => $this->eur('100.00')]);

        self::assertSame([2024, 2026], self::map($this->annual(2026)['availableYears'])['years']);
    }

    public function testAnAccountOverTheOperationBoundIsRefused(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code,
                gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale,
                state, created_at, created_by, version)
            SELECT ('00000000-0000-7000-8000-' || lpad(to_hex(n), 12, '0'))::uuid, ?::uuid, ?::uuid, 'DEPOSIT', '2026-03-10'::date, n, 'EUR',
                1, 0, 0, 0, 0, 0, 1, 0, 'ACTIVE', now(), ?::uuid, 1
            FROM generate_series(1, 5001) AS n
            SQL, [WorkspaceFixture::OWN_WORKSPACE, self::ACCOUNT, WorkspaceFixture::OWNER_ID]);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance/annual?year=2026');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('performance_range_too_dense', $this->decode()['code']);
    }

    public function testForeignUnknownAndNonPortfolioAccountsAreRefused(): void
    {
        $this->seedThroughApril();

        $this->get('/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-performance/annual?year=2026');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Voisin', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('modifiedDietz', (string) $this->client->getResponse()->getContent());

        foreach (['00000000-0000-7000-8000-0000000000ff', 'not-an-id'] as $id) {
            $this->get('/api/v1/accounts/'.$id.'/portfolio-performance/annual?year=2026');
            self::assertResponseStatusCodeSame(404, $id);
        }

        $this->get('/api/v1/accounts/'.self::SNAPSHOTS_ACCOUNT.'/portfolio-performance/annual?year=2026');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('account_not_portfolio', $this->decode()['code']);
    }

    public function testTheAnswerIsNotCached(): void
    {
        $this->annual(2026);

        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * @param array<string, mixed> $single a portfolio-performance response
     *
     * @return array<string, mixed> the period line the annual response must carry for it
     */
    private function expectedLine(array $single): array
    {
        $gain = self::map($single['gain']);
        $decomposition = self::map($gain['decomposition']);

        return [
            'from' => $gain['from'], 'to' => $gain['to'],
            'startValue' => $gain['startValue'], 'endValue' => $gain['endValue'], 'netFlows' => $gain['netFlows'],
            'gain' => $gain['value'],
            'realized' => $decomposition['realized'], 'unrealizedChange' => $decomposition['unrealizedChange'],
            'income' => $decomposition['income'], 'fees' => $decomposition['fees'], 'taxes' => $decomposition['taxes'],
            'gainQuality' => $gain['quality'], 'gainReasons' => $gain['reasons'],
            'modifiedDietz' => $this->rate(self::map($single['modifiedDietz'])),
            'timeWeighted' => $this->rate(self::map($single['timeWeighted'])),
            'moneyWeighted' => $this->expectedMoneyWeighted(self::map($single['moneyWeighted'])),
        ];
    }

    /**
     * @param array<string, mixed> $figure
     *
     * @return array<string, mixed>
     */
    private function rate(array $figure): array
    {
        return ['value' => $figure['value'], 'quality' => $figure['quality'], 'reasons' => $figure['reasons']];
    }

    /**
     * @param array<string, mixed> $figure
     *
     * @return array<string, mixed>
     */
    private function expectedMoneyWeighted(array $figure): array
    {
        return ['annualizedRate' => $figure['annualizedRate'], 'periodRate' => $figure['periodRate'], 'shortPeriod' => $figure['shortPeriod'], 'quality' => $figure['quality'], 'reasons' => $figure['reasons']];
    }

    /** @param list<string> $amounts */
    private function sum(array $amounts): string
    {
        $cents = 0;
        foreach ($amounts as $amount) {
            self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/D', $amount);
            $cents += (int) str_replace('.', '', $amount);
        }

        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    private function seedThroughApril(): void
    {
        $this->seedReferenceMonths();
        $this->recordTotal('2026-04-30', '11100.00');
    }

    private function seedReferenceMonths(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-02-01', 'grossAmount' => $this->eur('9798.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-02-02', 'instrumentId' => self::ETF, 'quantity' => '92', 'unitPrice' => '97.8043', 'grossAmount' => $this->eur('8998.00')]);
        $this->recordPositions('2026-02-28', [['instrumentId' => self::ETF, 'price' => '100.00', 'priceDate' => '2026-02-28']]);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-10', 'grossAmount' => $this->eur('1000.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-10', 'instrumentId' => self::ETF, 'quantity' => '8', 'unitPrice' => '100.00', 'grossAmount' => $this->eur('800.00'), 'fees' => $this->eur('2.00')]);
        $this->record(['type' => 'DIVIDEND', 'tradeDate' => '2026-03-15', 'instrumentId' => self::ETF, 'quantity' => '100', 'grossAmount' => $this->eur('30.00'), 'taxes' => $this->eur('9.00')]);
        $this->record(['type' => 'SELL', 'tradeDate' => '2026-03-20', 'instrumentId' => self::ETF, 'quantity' => '10', 'unitPrice' => '105.00', 'grossAmount' => $this->eur('1050.00'), 'fees' => $this->eur('3.00')]);
        $this->record(['type' => 'WITHDRAWAL', 'tradeDate' => '2026-03-25', 'grossAmount' => $this->eur('200.00')]);
        $this->record(['type' => 'FEE', 'tradeDate' => '2026-03-28', 'fees' => $this->eur('5.00')]);
        $this->recordPositions('2026-03-31', [['instrumentId' => self::ETF, 'price' => '102.10', 'priceDate' => '2026-03-31']]);
    }

    /** @return array<string, mixed> */
    private function annual(int $year): array
    {
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance/annual?year='.$year);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function single(string $scope, ?string $period): array
    {
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance?scope='.$scope.(null === $period ? '' : '&period='.$period));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    private function recordTotal(string $valuedOn, string $total): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations', ['valuedOn' => $valuedOn, 'detail' => 'TOTAL', 'totalValue' => $this->eur($total)]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @param list<array<string, mixed>> $prices */
    private function recordPositions(string $valuedOn, array $prices): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations', ['valuedOn' => $valuedOn, 'detail' => 'POSITIONS', 'prices' => $prices]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, mixed> $body */
    private function record(array $body): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-operations', $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return array{value: string, assetCode: string} */
    private function eur(string $value): array
    {
        return ['value' => $value, 'assetCode' => 'EUR'];
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function get(string $uri): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        return self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $map
     * @param list<string>         $keys
     *
     * @return array<string, mixed>
     */
    private static function without(array $map, array $keys): array
    {
        return array_diff_key($map, array_flip($keys));
    }

    private static function nullableText(mixed $value): ?string
    {
        self::assertTrue(null === $value || is_string($value));

        return $value;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $list): array
    {
        self::assertIsList($list);

        return $list;
    }

    /** @return list<array<string, mixed>> */
    private static function lines(mixed $list): array
    {
        return array_map(self::map(...), self::list($list));
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
}
