<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PortfolioPerformanceControllerTest extends WebTestCase
{
    private const string ACCOUNT = PortfolioFixture::ACCOUNT;
    private const string ETF = PortfolioFixture::ETF;
    private const string STOCK = PortfolioFixture::STOCK;
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d7';
    private const string SNAPSHOTS_ACCOUNT = '00000000-0000-7000-8000-0000000000d8';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private PortfolioFixture $portfolio;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
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

        $this->portfolio = new PortfolioFixture($connection);
        $this->portfolio->account();
        $this->portfolio->account(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->portfolio->account(self::SNAPSHOTS_ACCOUNT, label: 'Assurance-vie', valuationMode: 'SNAPSHOTS');
        $this->portfolio->instrument(self::ETF, name: 'ETF Monde Démo', isin: 'IE00B4L5Y983');
        $this->portfolio->instrument(self::STOCK, name: 'Zeta Industrie Démo', isin: 'FR0000120073', quantityPrecision: 12, pricePrecision: 12);

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

    public function testTheReferenceMonthIsExactAndFullyDecomposed(): void
    {
        $this->seedReferenceMonth();

        $performance = $this->performance('MONTH', '2026-03');

        self::assertSame('2026-03', $performance['period']);
        self::assertSame('PERF-1', $performance['methodVersion']);
        self::assertSame('NET_OF_FEES_AND_TAXES', $performance['costPolicy']);
        self::assertSame('EUR', $performance['assetCode']);
        self::assertSame('800.00', $performance['netFlows']);
        $start = self::map($performance['start']);
        $end = self::map($performance['end']);
        self::assertSame(['VALUATION', '2026-02-28', '10000.00', 'POSITIONS'], [$start['kind'], $start['date'], $start['totalValue'], $start['detail']]);
        self::assertSame(['2026-03-31', '11050.00'], [$end['date'], $end['totalValue']]);
        $flows = self::items2($performance['flows']);
        self::assertSame(2, $performance['flowCount']);
        self::assertSame(['DEPOSIT', '2026-03-10', '1000.00'], [self::map($flows[0])['type'], self::map($flows[0])['date'], self::map($flows[0])['amount']]);
        self::assertSame(['WITHDRAWAL', '2026-03-25', '-200.00'], [self::map($flows[1])['type'], self::map($flows[1])['date'], self::map($flows[1])['amount']]);
        $gain = self::map($performance['gain']);
        self::assertSame('250.00', $gain['value']);
        self::assertSame('TOTAL_GAIN', $gain['method']);
        self::assertSame('EXACT', $gain['quality']);
        self::assertSame([], $gain['reasons']);
        self::assertSame(['2026-02-28', '2026-03-31', 31], [$gain['from'], $gain['to'], $gain['days']]);
        self::assertSame(['10000.00', '11050.00', '800.00'], [$gain['startValue'], $gain['endValue'], $gain['netFlows']]);
        $decomposition = self::map($gain['decomposition']);
        self::assertSame(
            ['COMPLETE', null, '70.00', '167.00', '30.00', '-8.00', '-9.00', '0.00', '2.00', '0.00'],
            array_values($decomposition),
        );
        $sources = self::items2($gain['sources']);
        self::assertSame(['VALUATION', 'VALUATION'], [self::map($sources[0])['kind'], self::map($sources[1])['kind']]);
        self::assertSame(8, $gain['sourceCount'], 'two anchors and six operations');
    }

    public function testTimeWeightedReturnRequiresActiveFlowDayValuations(): void
    {
        $this->seedReferenceMonth();
        $missing = self::map($this->performance('MONTH', '2026-03')['timeWeighted']);
        self::assertNull($missing['value']);
        self::assertSame(2, $missing['missingCount']);
        self::assertSame(['2026-03-10', '2026-03-25'], self::map(self::items2($missing['reasons'])[0])['dates']);
        $this->recordTotal('2026-03-10', '11100.00');
        $this->recordTotal('2026-03-25', '10950.00');
        $body = $this->performance('MONTH', '2026-03');
        $twr = self::map($body['timeWeighted']);
        self::assertSame(['0.023815', 'EXACT', 'TWR', 3, 0], [$twr['value'], $twr['quality'], $twr['method'], $twr['subperiodCount'], $twr['missingCount']]);
        self::assertSame('0.023499', self::map($body['modifiedDietz'])['value']);
        $superseded = $this->recordTotal('2026-03-25', '10950.00', ['version' => 1]);
        $this->connection->executeStatement('UPDATE portfolio_valuations SET superseded_at = now() WHERE workspace_id = ?::uuid AND id = ?::uuid', [WorkspaceFixture::OWN_WORKSPACE, $superseded['id']]);
        $missing = self::map($this->performance('MONTH', '2026-03')['timeWeighted']);
        self::assertNull($missing['value']);
        self::assertSame(1, $missing['missingCount']);
        $this->recordTotal('2026-03-25', '10950.00');
        self::assertSame('0.023815', self::map($this->performance('MONTH', '2026-03')['timeWeighted'])['value']);
        foreach (['YEAR', 'INCEPTION'] as $scope) {
            $figure = self::map($this->performance($scope, 'YEAR' === $scope ? '2026' : null)['timeWeighted']);
            self::assertSame('TWR', $figure['method']);
            self::assertArrayHasKey('subperiods', $figure);
        }
    }

    public function testConsecutiveMonthsShareTheirBoundaryAnchor(): void
    {
        $this->seedReferenceMonth();
        $this->recordTotal('2026-04-30', '11100.00');

        $april = $this->performance('MONTH', '2026-04');

        self::assertSame('2026-03-31', self::map($april['start'])['date']);
        self::assertSame('2026-04-30', self::map($april['end'])['date']);
        self::assertSame('50.00', self::map($april['gain'])['value']);
        self::assertSame('0.00', $april['netFlows']);
    }

    public function testAMonthWithoutAnEndValuationIsNeverZero(): void
    {
        $this->seedReferenceMonth();
        $this->recordTotal('2026-04-30', '11100.00');

        $may = self::map($this->performance('MONTH', '2026-05')['gain']);

        self::assertNull($may['value']);
        self::assertSame('NOT_CALCULABLE', $may['quality']);
        self::assertSame([['code' => 'NO_END_VALUATION', 'dates' => ['2026-05-31']]], $may['reasons']);
        self::assertNull(self::map($may['decomposition'])['realized']);
    }

    public function testAPartialEndValuationNeverAnchorsAndIsReported(): void
    {
        $this->seedReferenceMonth();
        $this->recordPositions('2026-04-30', [['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null]]);

        $april = self::map($this->performance('MONTH', '2026-04')['gain']);

        self::assertNull($april['value']);
        self::assertSame('END_VALUATION_PARTIAL', self::map(self::items2($april['reasons'])[0])['code']);
    }

    public function testALaterPartialValuationOfTheMonthDoesNotReplaceTheCompleteAnchor(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-01', 'grossAmount' => $this->eur('1000.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-02', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '100.00', 'grossAmount' => $this->eur('100.00')]);
        $this->recordTotal('2026-03-30', '1010.00');
        $this->recordPositions('2026-03-31', [['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null]]);

        $performance = $this->performance('MONTH', '2026-03');

        self::assertSame('10.00', self::map($performance['gain'])['value']);
        self::assertSame('2026-03-30', self::map($performance['end'])['date']);
    }

    public function testOperationsOnTheStartDateAreExcludedAndOnTheEndDateIncluded(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-05', 'grossAmount' => $this->eur('1000.00')]);
        $this->recordTotal('2026-03-31', '1000.00');
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-31', 'grossAmount' => $this->eur('50.00')]);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-04-30', 'grossAmount' => $this->eur('200.00')]);
        $this->recordTotal('2026-04-30', '1250.00');

        $april = $this->performance('MONTH', '2026-04');

        self::assertSame(1, $april['flowCount'], 'the deposit on the start anchor date belongs to the previous period');
        self::assertSame('200.00', $april['netFlows']);
        self::assertSame('50.00', self::map($april['gain'])['value']);
        self::assertSame('1050.00', $this->performance('MONTH', '2026-03')['netFlows']);
    }

    public function testASupersededValuationOfTheMonthIsIgnored(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-05', 'grossAmount' => $this->eur('1000.00')]);
        $this->recordTotal('2026-03-20', '1010.00');
        $later = $this->recordTotal('2026-03-31', '9999.00');
        $this->connection->executeStatement('UPDATE portfolio_valuations SET superseded_at = now() WHERE id = ?::uuid', [$later['id']]);

        $march = $this->performance('MONTH', '2026-03');

        self::assertSame('2026-03-20', self::map($march['end'])['date']);
        self::assertSame('10.00', self::map($march['gain'])['value']);
    }

    public function testAPartialValuationInTheStartMonthGivesStartValuationPartial(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-02-05', 'grossAmount' => $this->eur('1000.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-02-06', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '100.00', 'grossAmount' => $this->eur('100.00')]);
        $this->recordPositions('2026-02-28', [['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null]]);
        $this->recordTotal('2026-03-31', '1010.00');

        $gain = self::map($this->performance('MONTH', '2026-03')['gain']);

        self::assertNull($gain['value']);
        self::assertSame([['code' => 'START_VALUATION_PARTIAL', 'dates' => ['2026-02-28']]], $gain['reasons']);
    }

    public function testASecurityTransferInsideThePeriodMakesTheGainNull(): void
    {
        $this->seedReferenceMonth();
        $this->record(['type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-04-10', 'instrumentId' => self::STOCK, 'quantity' => '3']);
        $this->recordTotal('2026-04-30', '11100.00');

        $performance = $this->performance('MONTH', '2026-04');
        $gain = self::map($performance['gain']);

        self::assertNull($gain['value']);
        self::assertNull($performance['netFlows']);
        self::assertSame([['code' => 'UNVALUED_SECURITY_TRANSFER', 'dates' => ['2026-04-10']]], $gain['reasons']);
        self::assertSame(1, $performance['flowCount']);
        self::assertNull(self::map(self::items2($performance['flows'])[0])['amount']);
    }

    public function testAnUnknownAverageCostKeepsTheGainButNotTheDecomposition(): void
    {
        $this->record(['type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-02-01', 'instrumentId' => self::ETF, 'quantity' => '10']);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-02-02', 'grossAmount' => $this->eur('1000.00')]);
        $this->recordPositions('2026-02-28', [['instrumentId' => self::ETF, 'price' => '100.00', 'priceDate' => '2026-02-28']]);
        $this->recordPositions('2026-03-31', [['instrumentId' => self::ETF, 'price' => '103.00', 'priceDate' => '2026-03-31']]);

        $gain = self::map($this->performance('MONTH', '2026-03')['gain']);

        self::assertSame('30.00', $gain['value']);
        $decomposition = self::map($gain['decomposition']);
        self::assertSame('UNAVAILABLE', $decomposition['status']);
        self::assertSame('UNKNOWN_LOT_COST', $decomposition['reason']);
        self::assertNull($decomposition['unrealizedChange']);
        self::assertNull($decomposition['residual']);
    }

    public function testTheOpeningMonthStartsFromAnOpeningAnchorOfValueZero(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-01-10', 'grossAmount' => $this->eur('500.00')]);
        $this->recordTotal('2026-01-31', '510.00');

        $performance = $this->performance('MONTH', '2026-01');

        $start = self::map($performance['start']);
        self::assertSame(['OPENING', null, '2025-12-31', '0.00'], [$start['kind'], $start['valuationId'], $start['date'], $start['totalValue']]);
        self::assertSame('10.00', self::map($performance['gain'])['value']);
    }

    public function testAYearAndTheWholeLifeUseTheirOwnBounds(): void
    {
        $this->seedReferenceMonth();
        $this->recordTotal('2026-04-30', '11100.00');

        $year = $this->performance('YEAR', '2026');
        self::assertSame('OPENING', self::map($year['start'])['kind']);
        self::assertSame('2026-04-30', self::map($year['end'])['date']);
        self::assertSame('PERIOD_IN_PROGRESS', self::map(self::items2(self::map($year['gain'])['reasons'])[0])['code']);
        self::assertSame('DEGRADED', self::map($year['gain'])['quality']);

        $life = $this->performance('INCEPTION', null);
        self::assertNull($life['period']);
        self::assertSame('2026-01-31', self::map($life['start'])['date'], 'the day before the first operation');
        self::assertSame('2026-04-30', self::map($life['end'])['date']);
    }

    public function testTheReferenceMonthGivesItsModifiedDietzReturnAndWeights(): void
    {
        $this->seedReferenceMonth();

        $dietz = self::map($this->performance('MONTH', '2026-03')['modifiedDietz']);

        self::assertSame(['0.023499', 'MODIFIED_DIETZ', 'APPROXIMATION', []], [$dietz['value'], $dietz['method'], $dietz['quality'], $dietz['reasons']]);
        self::assertSame(['2026-02-28', '2026-03-31', 31], [$dietz['from'], $dietz['to'], $dietz['days']]);
        self::assertSame(['10638.709677', '0.10', 2, null], [$dietz['averageCapital'], $dietz['minCapitalRatio'], $dietz['weightCount'], $dietz['months']]);
        $weights = self::items2($dietz['weights']);
        self::assertSame(['2026-03-10', '1000.00', '21', '0.677419'], [self::map($weights[0])['date'], self::map($weights[0])['amount'], self::map($weights[0])['remainingDays'], self::map($weights[0])['weight']]);
        self::assertSame(['-200.00', '6', '0.193548'], [self::map($weights[1])['amount'], self::map($weights[1])['remainingDays'], self::map($weights[1])['weight']]);
    }

    public function testAYearChainsItsMonthsAndListsThem(): void
    {
        $this->seedReferenceMonth();
        $this->recordTotal('2026-04-30', '11100.00');

        $dietz = self::map($this->performance('YEAR', '2026')['modifiedDietz']);

        self::assertSame(['0.050112', 'DEGRADED'], [$dietz['value'], $dietz['quality']], 'the year is still in progress');
        $months = array_map(static fn (mixed $month): array => [self::map($month)['month'], self::map($month)['value']], self::items2($dietz['months']));
        self::assertSame([['2026-02', '0.021380'], ['2026-03', '0.023499'], ['2026-04', '0.004525']], $months);
        self::assertSame([], $dietz['weights']);
        self::assertSame(0, $dietz['weightCount']);
        self::assertNull($dietz['averageCapital']);
    }

    public function testTheWholeLifeChainsFromTheOpeningMonth(): void
    {
        $this->seedReferenceMonth();
        $this->recordTotal('2026-04-30', '11100.00');

        $dietz = self::map($this->performance('INCEPTION', null)['modifiedDietz']);

        self::assertSame('0.050112', $dietz['value']);
        self::assertCount(3, self::items2($dietz['months']));
    }

    public function testAMonthThatCannotBeCalculatedMakesTheYearNullWithItsMonths(): void
    {
        $this->seedReferenceMonth();
        $this->connection->executeStatement("DELETE FROM portfolio_position_snapshots WHERE valuation_id IN (SELECT id FROM portfolio_valuations WHERE valued_on = '2026-03-31')");
        $this->connection->executeStatement("DELETE FROM portfolio_valuations WHERE valued_on = '2026-03-31'");
        $this->recordTotal('2026-04-30', '11100.00');

        $dietz = self::map($this->performance('YEAR', '2026')['modifiedDietz']);

        self::assertNull($dietz['value']);
        self::assertSame('NOT_CALCULABLE', $dietz['quality']);
        self::assertSame([['code' => 'MONTH_NOT_CALCULABLE', 'dates' => ['2026-03', '2026-04']], ['code' => 'PERIOD_IN_PROGRESS', 'dates' => ['2026-04-30']]], $dietz['reasons']);
        $months = self::items2($dietz['months']);
        self::assertSame(['2026-02', '0.021380'], [self::map($months[0])['month'], self::map($months[0])['value']]);
        self::assertNull(self::map($months[1])['value']);
        self::assertSame('NOT_CALCULABLE', self::map($months[1])['quality']);
    }

    public function testADepositLateInTheMonthGivesNoReturnBecauseTheCapitalIsTooSmall(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-29', 'grossAmount' => $this->eur('1000.00')]);
        $this->recordTotal('2026-03-31', '1010.00');

        $dietz = self::map($this->performance('MONTH', '2026-03')['modifiedDietz']);

        self::assertNull($dietz['value']);
        self::assertSame('NOT_CALCULABLE', $dietz['quality']);
        self::assertSame([['code' => 'DENOMINATOR_TOO_SMALL', 'dates' => []]], $dietz['reasons']);
        self::assertSame('64.516129', $dietz['averageCapital']);
    }

    public function testAnInheritedBlockingReasonMakesTheReturnNull(): void
    {
        $this->seedReferenceMonth();
        $this->recordTotal('2026-04-30', '11100.00');

        $dietz = self::map($this->performance('MONTH', '2026-05')['modifiedDietz']);

        self::assertNull($dietz['value']);
        self::assertSame([['code' => 'NO_END_VALUATION', 'dates' => ['2026-05-31']]], $dietz['reasons']);
        self::assertSame([], $dietz['weights']);
    }

    public function testAPriceOfAnAnchorThatIsStaleDegradesTheReturnButKeepsIt(): void
    {
        $this->seedReferenceMonth();
        $this->recordPositions('2026-04-30', [['instrumentId' => self::ETF, 'price' => '102.10', 'priceDate' => '2026-03-31']]);

        $dietz = self::map($this->performance('MONTH', '2026-04')['modifiedDietz']);

        self::assertNotNull($dietz['value']);
        self::assertSame('DEGRADED', $dietz['quality']);
        self::assertSame('STALE_PRICES', self::map(self::items2($dietz['reasons'])[0])['code']);
    }

    public function testAWholeLifeOfMoreThanSixHundredMonthsIsRefusedWithoutMonthlyWork(): void
    {
        $this->connection->executeStatement("UPDATE account_financial_accounts SET opened_on = '1970-01-01' WHERE id = ?", [self::ACCOUNT]);
        $this->seedOperations(1, '1970-01-01');
        $this->recordTotal('2026-03-31', '1.00');

        $dietz = self::map($this->performance('INCEPTION', null)['modifiedDietz']);

        self::assertNull($dietz['value']);
        self::assertSame([['code' => 'TOO_MANY_PERIODS', 'dates' => []]], $dietz['reasons']);
        self::assertNull($dietz['months']);
    }

    public function testALongWholeLifeKeepsTheWorkPerMonthBounded(): void
    {
        $this->connection->executeStatement("UPDATE account_financial_accounts SET opened_on = '2016-01-01' WHERE id = ?", [self::ACCOUNT]);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2016-03-15', 'grossAmount' => $this->eur('1000.00')]);
        $months = 0;
        for ($day = new \DateTimeImmutable('2016-03-31'); $day <= new \DateTimeImmutable('2026-03-31'); $day = $day->modify('last day of next month')) {
            $this->recordTotal($day->format('Y-m-d'), '1000.00');
            ++$months;
        }
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $dietz = self::map($this->performance('INCEPTION', null)['modifiedDietz']);

        self::assertSame('0.000000', $dietz['value']);
        self::assertCount($months, self::items2($dietz['months']));
        $queries = array_merge(...array_values($holder->getData()));
        $operationReads = count(array_filter($queries, static fn (array $query): bool => is_string($query['sql']) && str_contains($query['sql'], 'FROM portfolio_operations')));
        self::assertLessThanOrEqual(5, $operationReads, 'the history is read once, not once per month');
        // Each month resolves two anchors with a bounded number of valuation reads: the cost grows linearly, never per history size.
        self::assertLessThanOrEqual(12 * $months + 20, count($queries));
    }

    public function testFlowsAreTruncatedAtOneHundredWithTheExactCount(): void
    {
        $this->seedOperations(101, '2026-03-10');
        $this->recordTotal('2026-03-31', '101.00');

        $performance = $this->performance('MONTH', '2026-03');

        self::assertCount(100, self::items2($performance['flows']));
        self::assertSame(101, $performance['flowCount']);
        self::assertSame('0.00', self::map($performance['gain'])['value']);
        self::assertSame('101.00', $performance['netFlows']);
    }

    public function testAnAccountOverTheOperationBoundIsRefused(): void
    {
        $this->seedOperations(5001, '2026-03-10');

        $this->get($this->url('MONTH', '2026-03'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('performance_range_too_dense', $this->decode()['code']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRequests(): iterable
    {
        yield 'missing scope' => ['?period=2026-03'];
        yield 'unknown scope' => ['?scope=WEEK&period=2026-03'];
        yield 'array scope' => ['?scope[]=MONTH&period=2026-03'];
        yield 'future month' => ['?scope=MONTH&period=2999-01'];
        yield 'month before opening' => ['?scope=MONTH&period=2025-12'];
        yield 'malformed year' => ['?scope=YEAR&period=26'];
        yield 'inception with period' => ['?scope=INCEPTION&period=2026'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRequests')]
    public function testInvalidRequestsAreRefusedWithTheirCodes(string $query): void
    {
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance'.$query);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($this->decode()['code'], ['invalid_performance_scope', 'invalid_performance_period']);
    }

    public function testForeignUnknownAndNonPortfolioAccountsAreRefused(): void
    {
        $this->seedReferenceMonth();

        $this->get('/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-performance?scope=INCEPTION');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Voisin', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('modifiedDietz', (string) $this->client->getResponse()->getContent());
        foreach (['MONTH&period=2026-03', 'YEAR&period=2026'] as $query) {
            $this->get('/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-performance?scope='.$query);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('modifiedDietz', (string) $this->client->getResponse()->getContent());
        }

        $this->get('/api/v1/accounts/00000000-0000-7000-8000-0000000000ff/portfolio-performance?scope=INCEPTION');
        self::assertResponseStatusCodeSame(404);

        $this->get('/api/v1/accounts/not-an-id/portfolio-performance?scope=INCEPTION');
        self::assertResponseStatusCodeSame(404);

        $this->get('/api/v1/accounts/'.self::SNAPSHOTS_ACCOUNT.'/portfolio-performance?scope=INCEPTION');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('account_not_portfolio', $this->decode()['code']);
    }

    private function seedOperations(int $count, string $tradeDate): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code,
                gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale,
                state, created_at, created_by, version)
            SELECT ('00000000-0000-7000-8000-' || lpad(to_hex(n), 12, '0'))::uuid, ?::uuid, ?::uuid, 'DEPOSIT', ?::date, n, 'EUR',
                1, 0, 0, 0, 0, 0, 1, 0, 'ACTIVE', now(), ?::uuid, 1
            FROM generate_series(1, ?) AS n
            SQL, [WorkspaceFixture::OWN_WORKSPACE, self::ACCOUNT, $tradeDate, WorkspaceFixture::OWNER_ID, $count]);
    }

    private function seedReferenceMonth(): void
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
    private function performance(string $scope, ?string $period): array
    {
        $this->get($this->url($scope, $period));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    private function url(string $scope, ?string $period): string
    {
        return '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance?scope='.$scope.(null === $period ? '' : '&period='.$period);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function recordTotal(string $valuedOn, string $total, array $extra = []): array
    {
        $this->postValuation($this->totalBody($valuedOn, $total) + $extra);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /**
     * @param list<array<string, mixed>> $prices
     * @param array<string, mixed>       $extra
     *
     * @return array<string, mixed>
     */
    private function recordPositions(string $valuedOn, array $prices, array $extra = []): array
    {
        $this->postValuation(['valuedOn' => $valuedOn, 'detail' => 'POSITIONS', 'prices' => $prices] + $extra);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /** @return list<mixed> */
    private static function items2(mixed $list): array
    {
        self::assertIsList($list);

        return $list;
    }

    /** @return array<string, mixed> */
    private function totalBody(string $valuedOn, string $total): array
    {
        return ['valuedOn' => $valuedOn, 'detail' => 'TOTAL', 'totalValue' => $this->eur($total)];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function postValuation(array $body, array $headers = [], string $account = self::ACCOUNT): void
    {
        $this->send('POST', '/api/v1/accounts/'.$account.'/portfolio-valuations', $body, $headers);
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

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function send(string $method, string $uri, array $body, array $headers = []): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers], content: json_encode($body, JSON_THROW_ON_ERROR));
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
