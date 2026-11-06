<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Portfolio\Application\Performance\ComputePortfolioPerformance;
use App\Module\Portfolio\Domain\Performance\PerformanceHistoryStart;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Worked examples, with a CTO opened on 2026-10-01 and read on 2026-11-03. Instrument W (the fixture ETF, price
 * precision 4) and A (the fixture stock). Lines: W 5 for a cost of 480.00 at 101.5000 is worth 507.50 and costs
 * 480.00 / 5 = 96.0000 a unit; A 20 without cost at 50.0000 is worth 1000.00. Securities 1507.50, cash 120.00,
 * total 1627.50. November closes at 1660.05, so 1660.05 / 1627.50 - 1 = 0.020000; a December at 1676.6505 is
 * 1676.6505 / 1660.05 - 1 = 0.01, hence the indexes 102.00 and 103.02 and the cumulative 1.0302 - 1 = 0.030200.
 */
final class PortfolioTakeoverControllerTest extends WebTestCase
{
    use ClosesPeriods;

    private const string ACCOUNT = PortfolioFixture::ACCOUNT;
    private const string W = PortfolioFixture::ETF;
    private const string A = PortfolioFixture::STOCK;
    private const string USD_INSTRUMENT = '00000000-0000-7000-8000-000000000b03';
    private const string FOREIGN_INSTRUMENT = '00000000-0000-7000-8000-000000000b04';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d7';
    private const string SNAPSHOTS_ACCOUNT = '00000000-0000-7000-8000-0000000000d8';
    private const string KEY = 'takeover-key-00000001';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private PortfolioFixture $portfolio;
    private MockClock $clock;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->clock = new MockClock('2026-11-03T12:00:00+00:00');
        self::getContainer()->set(ClockInterface::class, $this->clock);
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
        $this->connection->executeStatement("UPDATE account_financial_accounts SET opened_on = '2026-10-01', updated_at = now() WHERE id IN (:own, :foreign)", ['own' => self::ACCOUNT, 'foreign' => self::FOREIGN_ACCOUNT]);
        $this->portfolio->account(self::SNAPSHOTS_ACCOUNT, label: 'Assurance-vie', valuationMode: 'SNAPSHOTS');
        $this->portfolio->instrument(self::W, name: 'ETF Monde Démo', isin: 'IE00B4L5Y983');
        $this->portfolio->instrument(self::A, name: 'Zeta Industrie Démo', isin: 'FR0000120073');
        $this->portfolio->instrument(self::USD_INSTRUMENT, name: 'Action US Démo', isin: 'US0378331005', currency: 'USD');
        $this->portfolio->instrument(self::FOREIGN_INSTRUMENT, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin ETF', 'IE00B3RBWM25');

        $this->login();
    }

    private function login(): void
    {
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

    public function testAFreshSecuritiesAccountIsEligibleWithTheLastMonthEndAsDefaultDate(): void
    {
        $state = $this->state(self::ACCOUNT);

        self::assertTrue($state['eligible']);
        self::assertNull($state['reason']);
        self::assertSame('2026-10-31', $state['defaultDate']);
        self::assertNull($state['takeover']);
    }

    public function testTheDefaultDateIsTheOpeningDayWhenItIsLater(): void
    {
        $this->connection->executeStatement("UPDATE account_financial_accounts SET opened_on = '2026-11-02', updated_at = now() WHERE id = :id", ['id' => self::ACCOUNT]);

        self::assertSame('2026-11-02', $this->state(self::ACCOUNT)['defaultDate']);
    }

    public function testThePreviewValuesTheLinesAndTheUnitCostWithoutWritingAnything(): void
    {
        $this->post('/preview', $this->body('2026-10-31'));

        self::assertResponseStatusCodeSame(200);
        $preview = $this->decode();
        self::assertSame([], $preview['errors']);
        self::assertSame([
            ['instrumentId' => self::W, 'value' => '507.50', 'averageUnitCost' => '96.0000'],
            ['instrumentId' => self::A, 'value' => '1000.00', 'averageUnitCost' => null],
        ], $preview['lines']);
        self::assertSame('1507.50', $preview['securitiesValue']);
        self::assertSame('120.00', $preview['cash']);
        self::assertSame('1627.50', $preview['totalValue']);
        self::assertSame(0, $this->rows('portfolio_operations'));
        self::assertSame(0, $this->rows('portfolio_valuations'));
        self::assertSame(0, $this->rows('portfolio_takeovers'));
        self::assertSame(0, $this->rows('audit_events', "event_type LIKE 'portfolio\\_%'"));
    }

    public function testThePreviewListsEveryProblemAndKeepsTheOtherLinesValued(): void
    {
        $body = $this->body('2026-10-31');
        $body['lines'][0]['quantity'] = '0';
        $body['lines'][1]['price'] = '50.12345';
        $body['cash'] = '-1.00';
        $this->post('/preview', $body);

        self::assertResponseStatusCodeSame(200);
        $preview = $this->decode();
        self::assertSame([
            ['line' => null, 'field' => 'cash', 'code' => 'negative'],
            ['line' => 1, 'field' => 'quantity', 'code' => 'not_positive'],
            ['line' => 2, 'field' => 'price', 'code' => 'price_precision_exceeded'],
        ], $preview['errors']);
        self::assertNull(self::member($preview, 'lines', 0, 'value'));
        self::assertNull(self::member($preview, 'lines', 1, 'value'));
        self::assertNull($preview['securitiesValue']);
        self::assertNull($preview['totalValue']);
        self::assertSame('0', $preview['cash']);
    }

    public function testThePreviewValueEqualsTheRecordedValuationValueBeyondTheDisplayPrecision(): void
    {
        $body = $this->body('2026-10-31');
        $body['lines'] = [['instrumentId' => self::W, 'quantity' => '3.333', 'costBasis' => null, 'price' => '10.1111', 'priceDate' => null]];
        $body['cash'] = null;
        $this->post('/preview', $body);
        $previewed = self::member($this->decode(), 'lines', 0, 'value');
        self::assertSame('33.7002963', $previewed);

        $this->post('', $body, self::KEY);
        self::assertResponseStatusCodeSame(201);
        $recorded = $this->connection->fetchOne('SELECT market_value_value FROM portfolio_position_snapshots');
        self::assertSame($previewed, self::plain($recorded));
    }

    public function testAHugeProductIsAnErrorOnItsLineAndNotAnOverflow(): void
    {
        $body = $this->body('2026-10-31');
        $body['lines'][0]['quantity'] = '99999999999999';
        $body['lines'][0]['price'] = '99999999999999';
        $this->post('/preview', $body);

        self::assertResponseStatusCodeSame(200);
        self::assertContains(['line' => 1, 'field' => 'price', 'code' => 'value_too_large'], self::listAt($this->decode(), 'errors'));
    }

    public function testATakeoverAtAMonthEndRecordsTheTransfersTheDepositAndTheValuationInOnePiece(): void
    {
        $this->post('', $this->body('2026-10-31'), self::KEY);

        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $takeover = $this->decode();
        self::assertSame(self::ACCOUNT, $takeover['accountId']);
        self::assertSame('2026-10-31', $takeover['takenOverOn']);
        self::assertCount(3, self::listAt($takeover, 'operationIds'));

        $operations = $this->connection->fetchAllAssociative('SELECT operation_type, trade_date, instrument_id, quantity_value, cost_basis_value, gross_amount_value, state FROM portfolio_operations WHERE account_id = ? ORDER BY sequence', [self::ACCOUNT]);
        self::assertSame(['SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_IN', 'DEPOSIT'], array_column($operations, 'operation_type'));
        self::assertSame(['2026-10-31'], array_values(array_unique(array_map(static fn (array $operation): string => self::plain($operation['trade_date']), $operations))));
        self::assertSame('480', self::plain($operations[0]['cost_basis_value']));
        self::assertNull($operations[1]['cost_basis_value']);
        self::assertSame('120', self::plain($operations[2]['gross_amount_value']));
        $valuation = $this->connection->fetchAssociative('SELECT valued_on, detail, completeness, total_value_value, securities_value_value, cash_value, cash_source, note, observed_total_value FROM portfolio_valuations WHERE account_id = ?', [self::ACCOUNT]);
        self::assertIsArray($valuation);
        self::assertSame(['valued_on' => '2026-10-31', 'detail' => 'POSITIONS', 'completeness' => 'COMPLETE', 'total_value' => '1627.5', 'securities' => '1507.5', 'cash' => '120'], [
            'valued_on' => $valuation['valued_on'], 'detail' => $valuation['detail'], 'completeness' => $valuation['completeness'],
            'total_value' => self::plain($valuation['total_value_value']), 'securities' => self::plain($valuation['securities_value_value']), 'cash' => self::plain($valuation['cash_value']),
        ]);
        self::assertSame('DERIVED', $valuation['cash_source']);
        self::assertSame('Reprise des positions.', $valuation['note']);
        self::assertNull($valuation['observed_total_value']);
        self::assertSame(1, $this->rows('portfolio_takeovers'));
        self::assertSame('1627.5', self::plain($this->connection->fetchOne("SELECT amount_literal FROM account_balance_snapshots WHERE account_id = ? AND source = 'CALCULATED' AND superseded_at IS NULL", [self::ACCOUNT])));
        self::assertSame(1, $this->rows('audit_events', "event_type = 'portfolio_takeover.recorded'"));

        $state = $this->state(self::ACCOUNT);
        self::assertFalse($state['eligible']);
        self::assertSame('ALREADY_TAKEN_OVER', $state['reason']);
        self::assertIsArray($state['takeover']);
        self::assertSame($takeover['id'], self::member($state, 'takeover', 'id'));
        self::assertSame($takeover['valuationId'], self::member($state, 'takeover', 'valuationId'));
        self::assertSame($takeover['operationIds'], self::member($state, 'takeover', 'operationIds'));
    }

    public function testTheAuditTrailCarriesIdentifiersAndCountsOnly(): void
    {
        $this->post('', $this->body('2026-10-31'), self::KEY);
        self::assertResponseStatusCodeSame(201);

        $event = $this->connection->fetchOne("SELECT after_json::text FROM audit_events WHERE event_type = 'portfolio_takeover.recorded'");
        self::assertIsString($event);
        self::assertStringContainsString('lineCount', $event);
        foreach (['Monde', 'Zeta', '480', '101.5', '1627', '120.00'] as $secret) {
            self::assertStringNotContainsString($secret, $event);
        }
    }

    public function testTheMonthOfAMonthEndTakeoverIsBeforeTheTakeoverAndTheNextOneIsCalculable(): void
    {
        $this->takeOver('2026-10-31');
        $this->at('2026-12-01T12:00:00+00:00');
        $this->valuation('2026-11-30', '1660.05');

        $october = $this->performance('2026-10');
        self::assertNull(self::member($october, 'modifiedDietz', 'value'));
        self::assertSame('NOT_CALCULABLE', self::member($october, 'modifiedDietz', 'quality'));
        self::assertSame([['code' => 'BEFORE_TAKEOVER', 'dates' => ['2026-10-31']]], self::member($october, 'modifiedDietz', 'reasons'));
        self::assertNull(self::member($october, 'gain', 'value'));
        self::assertNull($october['start']);

        $november = $this->performance('2026-11');
        self::assertSame('0.020000', self::member($november, 'modifiedDietz', 'value'));
        self::assertSame('EXACT', self::member($november, 'gain', 'quality'));
        self::assertSame([], self::member($november, 'modifiedDietz', 'reasons'));
        self::assertSame('1627.50', self::member($november, 'start', 'totalValue'));
        self::assertSame('2026-10-31', self::member($november, 'start', 'date'));
        self::assertSame('1660.05', self::member($november, 'end', 'totalValue'));
    }

    public function testAMidMonthTakeoverMeasuresTheMonthFromTheTakeoverValuationAndSaysSo(): void
    {
        $this->takeOver('2026-11-03');
        $this->at('2026-12-01T12:00:00+00:00');
        $this->valuation('2026-11-30', '1660.05');

        $november = $this->performance('2026-11');
        self::assertSame('0.020000', self::member($november, 'modifiedDietz', 'value'));
        self::assertSame('DEGRADED', self::member($november, 'modifiedDietz', 'quality'));
        self::assertSame([['code' => 'PERIOD_STARTS_AT_TAKEOVER', 'dates' => ['2026-11-03']]], self::member($november, 'modifiedDietz', 'reasons'));
        self::assertSame('1627.50', self::member($november, 'start', 'totalValue'));
        self::assertSame('2026-11-03', self::member($november, 'start', 'date'));
        self::assertSame('DEGRADED', self::member($november, 'gain', 'quality'));
        self::assertSame('BEFORE_TAKEOVER', self::member($this->performance('2026-10'), 'modifiedDietz', 'reasons', 0, 'code'));
    }

    public function testTheInceptionStartsAtTheTakeoverValuation(): void
    {
        $this->takeOver('2026-10-31');
        $this->at('2026-12-01T12:00:00+00:00');
        $this->valuation('2026-11-30', '1660.05');

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance?scope=INCEPTION');
        self::assertResponseStatusCodeSame(200);
        $inception = $this->decode();

        self::assertSame('1627.50', self::member($inception, 'start', 'totalValue'));
        self::assertSame('2026-10-31', self::member($inception, 'start', 'date'));
        self::assertSame('0.020000', self::member($inception, 'modifiedDietz', 'value'));
    }

    public function testBothHistoryStartModesStartTheInceptionAtTheTakeoverValuation(): void
    {
        $this->takeOver('2026-10-31');
        $this->at('2026-12-01T12:00:00+00:00');
        $this->valuation('2026-11-30', '1660.05');
        $compute = self::getContainer()->get(ComputePortfolioPerformance::class);
        $accounts = self::getContainer()->get(AccountRepository::class);
        self::assertInstanceOf(ComputePortfolioPerformance::class, $compute);
        self::assertInstanceOf(AccountRepository::class, $accounts);
        $account = $accounts->find(WorkspaceFixture::own(), self::ACCOUNT);
        self::assertNotNull($account);
        $today = new \DateTimeImmutable('2026-12-01', new \DateTimeZone('UTC'));

        foreach ([PerformanceHistoryStart::FIRST_OBSERVATION, PerformanceHistoryStart::OPENING] as $mode) {
            $inception = $compute(WorkspaceFixture::own(), $account, PerformancePeriod::of('INCEPTION', null, $today, $account->openedOn), $today, null, $mode);

            self::assertSame('2026-10-31', $inception->start?->date->format('Y-m-d'), $mode->name);
            self::assertSame('0.020000', $inception->modifiedDietz->value, $mode->name);
        }
    }

    public function testTheReturnChainStartsAtTheFirstHistoryMonthInsteadOfBreaking(): void
    {
        $this->takeOver('2026-10-31');
        $this->at('2026-12-01T12:00:00+00:00');
        $this->valuation('2026-11-30', '1660.05');
        $this->at('2027-01-15T12:00:00+00:00');
        $this->valuation('2026-12-31', '1676.6505');

        $this->get('/api/v1/portfolios');
        self::assertResponseStatusCodeSame(200);
        $items = $this->decode()['items'];
        self::assertIsArray($items);
        $trend = null;
        foreach ($items as $item) {
            if (is_array($item) && self::ACCOUNT === ($item['accountId'] ?? null)) {
                $trend = $item['returnTrend'];
            }
        }
        self::assertIsArray($trend);
        self::assertSame('2026-01', self::member($trend, 'months', 0, 'month'));
        self::assertSame(array_fill(0, 10, null), array_column(array_slice(self::monthRows($trend), 0, 10), 'index'));
        self::assertSame(['102.00', '103.02'], array_column(array_slice(self::monthRows($trend), 10), 'index'));
        self::assertSame('2026-11', $trend['from']);
        self::assertSame('0.030200', $trend['cumulativeReturn']);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance/annual?year=2026');
        self::assertResponseStatusCodeSame(200);
        $annual = $this->decode();
        self::assertIsArray($annual['months']);
        $indexes = array_map(static fn (mixed $month): mixed => is_array($month) ? ($month['modifiedDietzIndex'] ?? null) : null, $annual['months']);
        self::assertSame(array_fill(0, 10, null), array_slice($indexes, 0, 10));
        self::assertSame(['102.00', '103.02'], array_slice($indexes, 10));
        self::assertIsArray(self::member($annual, 'months', 9));
        self::assertSame('NOT_CALCULABLE', self::member($annual, 'months', 9, 'status'));
        self::assertIsArray($annual['yearTotal']);
        self::assertIsArray(self::member($annual, 'yearTotal', 'modifiedDietz'));
        self::assertSame('0.030200', self::member($annual, 'yearTotal', 'modifiedDietz', 'value'));
    }

    public function testAnAccountWithABuyIsNotEligibleAndTheRecordIsRefused(): void
    {
        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => '2026-11-02', 'grossAmount' => $this->eur('1000.00')]);
        $this->operation(['type' => 'BUY', 'tradeDate' => '2026-11-02', 'instrumentId' => self::W, 'quantity' => '1', 'unitPrice' => '100.0000', 'grossAmount' => $this->eur('100.00')]);

        $state = $this->state(self::ACCOUNT);
        self::assertFalse($state['eligible']);
        self::assertSame('SECURITY_OPERATION', $state['reason']);

        $this->post('', $this->body('2026-11-03'), self::KEY);
        self::assertResponseStatusCodeSame(409);
        $problem = $this->decode();
        self::assertSame('portfolio_takeover_not_empty', $problem['code']);
        self::assertSame('SECURITY_OPERATION', $problem['blockingReason']);
        self::assertSame(2, $this->rows('portfolio_operations'));
        self::assertSame(0, $this->rows('portfolio_takeovers'));
    }

    public function testAnActiveValuationBlocksTheTakeover(): void
    {
        $this->valuation('2026-10-31', '0');

        self::assertSame('ACTIVE_VALUATION', $this->state(self::ACCOUNT)['reason']);
        $this->post('', $this->body('2026-11-03'), self::KEY);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ACTIVE_VALUATION', $this->decode()['blockingReason']);
    }

    public function testACashOperationOnOrBeforeTheDateBlocksButOneAfterItDoesNot(): void
    {
        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => '2026-11-02', 'grossAmount' => $this->eur('10.00')]);

        // Before the default date 2026-10-31 the deposit of 11-02 is later: still eligible.
        self::assertTrue($this->state(self::ACCOUNT)['eligible']);
        $this->post('', $this->body('2026-11-02'), self::KEY);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('CASH_OPERATION_BEFORE', $this->decode()['blockingReason']);

        $this->post('', $this->body('2026-10-31'), 'takeover-key-00000002');
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->rows('portfolio_takeovers'));
    }

    public function testAVoidedOperationDoesNotBlock(): void
    {
        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => '2026-10-15', 'grossAmount' => $this->eur('10.00')]);
        self::assertSame('CASH_OPERATION_BEFORE', $this->state(self::ACCOUNT)['reason']);
        $this->connection->executeStatement("UPDATE portfolio_operations SET state = 'VOIDED', voided_at = now(), voided_by = :owner, void_reason = 'test', void_kind = 'VOIDED' WHERE account_id = :account", ['owner' => WorkspaceFixture::OWNER_ID, 'account' => self::ACCOUNT]);

        self::assertTrue($this->state(self::ACCOUNT)['eligible']);
    }

    public function testASecondTakeoverIsRefusedAsExistingAndWritesNothingMore(): void
    {
        $this->takeOver('2026-10-31');

        $this->post('', $this->body('2026-10-31'), 'takeover-key-00000002');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('portfolio_takeover_exists', $this->decode()['code']);
        self::assertSame(3, $this->rows('portfolio_operations'));
        self::assertSame(1, $this->rows('portfolio_takeovers'));
    }

    public function testASnapshotsAccountIsNotSupported(): void
    {
        $state = $this->state(self::SNAPSHOTS_ACCOUNT);
        self::assertFalse($state['eligible']);
        self::assertSame('NOT_SUPPORTED', $state['reason']);

        $this->post('', $this->body('2026-11-03'), self::KEY, self::SNAPSHOTS_ACCOUNT);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('portfolio_takeover_not_supported', $this->decode()['code']);
        $this->post('/preview', $this->body('2026-11-03'), null, self::SNAPSHOTS_ACCOUNT);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAClosedOrArchivedAccountIsNotSupported(): void
    {
        $this->connection->executeStatement("UPDATE account_financial_accounts SET closed_on = '2026-10-20', updated_at = now() WHERE id = :id", ['id' => self::ACCOUNT]);

        self::assertSame('NOT_SUPPORTED', $this->state(self::ACCOUNT)['reason']);
        $this->post('', $this->body('2026-10-15'), self::KEY);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('portfolio_takeover_not_supported', $this->decode()['code']);
    }

    public function testAnotherWorkspaceAccountIsNotFoundOnThreeEndpointsAndItsInstrumentIsUnknown(): void
    {
        $this->get('/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-takeover');
        self::assertResponseStatusCodeSame(404);
        $this->post('/preview', $this->body('2026-10-31'), null, self::FOREIGN_ACCOUNT);
        self::assertResponseStatusCodeSame(404);
        $this->post('', $this->body('2026-10-31'), self::KEY, self::FOREIGN_ACCOUNT);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->rows('portfolio_takeovers'));
        self::assertSame(0, $this->rows('portfolio_operations'));

        $body = $this->body('2026-10-31');
        $body['lines'][0]['instrumentId'] = self::FOREIGN_INSTRUMENT;
        $this->post('', $body, 'takeover-key-00000002');
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['line' => 1, 'field' => 'instrumentId', 'code' => 'unknown_instrument']], self::listAt($this->decode(), 'errors'));
        self::assertSame(0, $this->rows('portfolio_takeovers'));
    }

    public function testAMalformedAccountIdentifierIsNotFound(): void
    {
        $this->get('/api/v1/accounts/not-a-uuid/portfolio-takeover');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnInstrumentInAnotherCurrencyRefusesTheWholeRequestAndLeavesNothing(): void
    {
        $body = $this->body('2026-10-31');
        $body['lines'][1]['instrumentId'] = self::USD_INSTRUMENT;
        $this->post('', $body, self::KEY);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->decode();
        self::assertSame('invalid_portfolio_takeover', $problem['code']);
        self::assertSame([['line' => 2, 'field' => 'instrumentId', 'code' => 'instrument_currency_mismatch']], $problem['errors']);
        self::assertSame(0, $this->rows('portfolio_operations'));
        self::assertSame(0, $this->rows('portfolio_valuations'));
        self::assertSame(0, $this->rows('portfolio_takeovers'));
        self::assertSame(0, $this->rows('audit_events', "event_type LIKE 'portfolio\_%'"));
    }

    public function testALimitReachedAfterTheFirstWriteIsAnUnprocessableRefusalThatRollsBack(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code, gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale, state, created_at, created_by, version)
             SELECT ('00000000-0000-7000-8005-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, 'DEPOSIT', '2026-11-02', n, 'EUR', 1, 0, 0, 0, 0, 0, 1, 0, 'ACTIVE', now(), :owner, 1 FROM generate_series(1, 9999) AS n",
            ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::ACCOUNT, 'owner' => WorkspaceFixture::OWNER_ID],
        );

        $this->post('', $this->body('2026-10-31'), self::KEY);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('operation_limit_reached', $this->decode()['code']);
        // The first transfer fitted under the limit and the second did not: the first is rolled back too.
        self::assertSame(9999, $this->rows('portfolio_operations'));
        self::assertSame(0, $this->rows('portfolio_takeovers'));
        self::assertSame(0, $this->rows('portfolio_valuations'));
    }

    /** @return iterable<string, array<mixed>> */
    public static function lineRules(): iterable
    {
        yield 'zero quantity' => [['quantity' => '0'], 'quantity', 'not_positive'];
        yield 'negative quantity' => [['quantity' => '-1'], 'quantity', 'not_positive'];
        yield 'quantity beyond the instrument precision' => [['quantity' => '1.1234567'], 'quantity', 'quantity_precision_exceeded'];
        yield 'malformed quantity' => [['quantity' => '1e3'], 'quantity', 'invalid_decimal'];
        yield 'quantity beyond storable decimals' => [['quantity' => '0.1234567890123456789012345'], 'quantity', 'precision_exceeded'];
        yield 'negative cost basis' => [['costBasis' => '-0.01'], 'costBasis', 'negative'];
        yield 'cost basis beyond the asset precision' => [['costBasis' => '1.1234567890123'], 'costBasis', 'precision_exceeded'];
        yield 'zero price' => [['price' => '0'], 'price', 'not_positive'];
        yield 'price beyond the instrument precision' => [['price' => '1.12345'], 'price', 'price_precision_exceeded'];
        yield 'price date after the takeover' => [['priceDate' => '2026-11-01'], 'priceDate', 'price_date_after_takeover'];
        yield 'invalid price date' => [['priceDate' => '2026-02-30'], 'priceDate', 'invalid_date'];
        yield 'unknown instrument' => [['instrumentId' => '00000000-0000-7000-8000-00000000ffff'], 'instrumentId', 'unknown_instrument'];
        yield 'not an identifier' => [['instrumentId' => 'abc'], 'instrumentId', 'unknown_instrument'];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('lineRules')]
    public function testEachLineRuleIsReportedOnItsLine(array $change, string $field, string $code): void
    {
        $body = $this->body('2026-10-31');
        $body['lines'][0] = [...$body['lines'][0], ...$change];
        $this->post('', $body, self::KEY);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_portfolio_takeover', $this->decode()['code']);
        self::assertSame([['line' => 1, 'field' => $field, 'code' => $code]], self::listAt($this->decode(), 'errors'));
        self::assertSame(0, $this->rows('portfolio_operations'));
        self::assertSame(0, $this->rows('portfolio_takeovers'));
    }

    public function testARepeatedInstrumentIsAnErrorOnTheSecondLine(): void
    {
        $body = $this->body('2026-10-31');
        $body['lines'][1]['instrumentId'] = self::W;
        $this->post('', $body, self::KEY);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['line' => 2, 'field' => 'instrumentId', 'code' => 'duplicate_instrument']], self::listAt($this->decode(), 'errors'));
    }

    public function testAnInactiveInstrumentIsRefused(): void
    {
        $this->connection->executeStatement("UPDATE portfolio_instruments SET status = 'INACTIVE' WHERE id = :id", ['id' => self::A]);
        $this->post('', $this->body('2026-10-31'), self::KEY);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['line' => 2, 'field' => 'instrumentId', 'code' => 'instrument_inactive']], self::listAt($this->decode(), 'errors'));
    }

    public function testTheLineCountIsBetweenOneAndTwoHundred(): void
    {
        $body = $this->body('2026-10-31');
        $body['lines'] = [];
        $this->post('', $body, self::KEY);
        self::assertResponseStatusCodeSame(422);
        self::assertContains(['line' => null, 'field' => 'lines', 'code' => 'lines_count'], self::listAt($this->decode(), 'errors'));

        $line = $this->body('2026-10-31')['lines'][0];
        $body['lines'] = array_fill(0, 201, $line);
        $this->post('', $body, 'takeover-key-00000002');
        self::assertResponseStatusCodeSame(422);
        self::assertContains(['line' => null, 'field' => 'lines', 'code' => 'lines_count'], self::listAt($this->decode(), 'errors'));
        self::assertSame(0, $this->rows('portfolio_operations'));
    }

    /** @return iterable<string, array<mixed>> */
    public static function dateRules(): iterable
    {
        yield 'before the opening' => ['2026-09-30', 'before_account_opening'];
        yield 'in the future' => ['2026-11-04', 'in_the_future'];
        yield 'not a day' => ['2026-02-30', 'invalid_date'];
        yield 'not a date' => ['yesterday', 'invalid_date'];
    }

    #[DataProvider('dateRules')]
    public function testTheTakeoverDateRules(string $date, string $code): void
    {
        $this->post('', $this->body($date), self::KEY);

        self::assertResponseStatusCodeSame(422);
        self::assertContains(['line' => null, 'field' => 'takenOverOn', 'code' => $code], self::listAt($this->decode(), 'errors'));
        self::assertSame(0, $this->rows('portfolio_takeovers'));
    }

    public function testAClosedMonthRefusesTheTakeoverBeforeAnythingIsWritten(): void
    {
        $this->closeMonthInDatabase($this->connection, 2026, 10);

        $this->post('', $this->body('2026-10-31'), self::KEY);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->rows('portfolio_operations'));
        self::assertSame(0, $this->rows('portfolio_takeovers'));
    }

    public function testAReplayWithTheSameKeyReturnsTheSameTakeoverAndWritesNothingMore(): void
    {
        $this->post('', $this->body('2026-10-31'), self::KEY);
        $first = $this->decode();
        $this->post('', $this->body('2026-10-31'), self::KEY);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        $replayed = $this->decode();
        ksort($replayed);
        ksort($first);
        self::assertSame($first, $replayed);
        self::assertSame(1, $this->rows('portfolio_takeovers'));
        self::assertSame(3, $this->rows('portfolio_operations'));

        $other = $this->body('2026-10-30');
        $this->post('', $other, self::KEY);
        self::assertResponseStatusCodeSame(409);
    }

    public function testTheKeyIsRequired(): void
    {
        $this->post('', $this->body('2026-10-31'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rows('portfolio_takeovers'));
    }

    public function testADeclaredShapeIsEnforced(): void
    {
        $this->send('POST', $this->url(''), ['takenOverOn' => '2026-10-31', 'lines' => [['instrumentId' => self::W, 'quantity' => 5, 'price' => '1']], 'extra' => 1], ['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_portfolio_takeover', $this->decode()['code']);
        self::assertSame(0, $this->rows('portfolio_operations'));
    }

    public function testACashOfZeroOrNullRecordsNoDeposit(): void
    {
        $body = $this->body('2026-10-31');
        $body['cash'] = '0';
        $this->post('', $body, self::KEY);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $this->rows('portfolio_operations', "operation_type = 'DEPOSIT'"));
        self::assertSame('1507.5', self::plain($this->connection->fetchOne('SELECT total_value_value FROM portfolio_valuations WHERE account_id = ?', [self::ACCOUNT])));
    }

    public function testAnUnauthenticatedCallerIsRefused(): void
    {
        $this->client->request('DELETE', '/api/v1/session');
        $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-takeover', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    /** @return array{takenOverOn: string, lines: list<array<string, string|null>>, cash: string|null} */
    private function body(string $date): array
    {
        return [
            'takenOverOn' => $date,
            'lines' => [
                ['instrumentId' => self::W, 'quantity' => '5', 'costBasis' => '480.00', 'price' => '101.5000', 'priceDate' => null],
                ['instrumentId' => self::A, 'quantity' => '20', 'costBasis' => null, 'price' => '50.0000', 'priceDate' => $date],
            ],
            'cash' => '120.00',
        ];
    }

    /** A session does not survive a jump of weeks: the clock moves and the owner signs in again. */
    private function at(string $moment): void
    {
        $this->clock->modify($moment);
        $this->login();
    }

    /**
     * @return array<mixed>
     */
    private static function listAt(mixed $value, string|int ...$path): array
    {
        $member = self::member($value, ...$path);
        self::assertIsArray($member);

        return $member;
    }

    /** Reads a member of a decoded JSON document along a path of keys. */
    private static function member(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<mixed> $trend
     *
     * @return list<array<mixed>>
     */
    private static function monthRows(array $trend): array
    {
        $months = self::member($trend, 'months');
        self::assertIsArray($months);

        return array_values(array_filter($months, is_array(...)));
    }

    private static function plain(mixed $decimal): string
    {
        self::assertIsString($decimal);

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    private function takeOver(string $date): void
    {
        $this->post('', $this->body($date), 'takeover-key-'.str_replace('-', '', $date));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    private function valuation(string $date, string $total): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations', ['valuedOn' => $date, 'detail' => 'TOTAL', 'totalValue' => $this->eur($total)]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, mixed> $body */
    private function operation(array $body): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-operations', $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function performance(string $month): array
    {
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-performance?scope=MONTH&period='.$month);
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function state(string $account): array
    {
        $this->get('/api/v1/accounts/'.$account.'/portfolio-takeover');
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    private function url(string $suffix, string $account = self::ACCOUNT): string
    {
        return '/api/v1/accounts/'.$account.'/portfolio-takeover'.$suffix;
    }

    /** @param array<string, mixed> $body */
    private function post(string $suffix, array $body, ?string $key = null, string $account = self::ACCOUNT): void
    {
        $this->send('POST', $this->url($suffix, $account), $body, null === $key ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key]);
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

    private function rows(string $table, string $where = 'true'): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM '.$table.' WHERE '.$where);
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $typed = [];
        foreach ($decoded as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
