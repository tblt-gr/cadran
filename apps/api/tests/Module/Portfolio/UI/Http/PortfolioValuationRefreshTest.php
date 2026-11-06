<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Portfolio\Infrastructure\Persistence\ValuationRow;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The valuation of the day follows every trade and cash flow of a securities portfolio, from what the last complete
 * valuation knew and what the operation says. Base: A x10 @100.00 = 1000.00, cash 500.00, total 1500.00.
 */
final class PortfolioValuationRefreshTest extends WebTestCase
{
    private const string PORTFOLIO = PortfolioFixture::ACCOUNT;
    private const string OTHER_PORTFOLIO = '00000000-0000-7000-8000-0000000000d9';
    private const string A = PortfolioFixture::ETF;
    private const string B = PortfolioFixture::STOCK;
    private const string NOTE = 'Mise à jour automatique après une opération.';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
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

        $portfolio = new PortfolioFixture($connection);
        $portfolio->account();
        $portfolio->account(self::OTHER_PORTFOLIO, label: 'Second compte-titres');
        $portfolio->instrument(self::A, name: 'ETF Monde', isin: 'IE00B4L5Y983');
        $portfolio->instrument(self::B, name: 'Action B', isin: 'FR0000120271');

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

    public function testABuyOfAnHeldInstrumentCarriesItsPriceAndMovesTheCash(): void
    {
        $this->baseWithPositions();

        $this->operation($this->buy(self::A, '2', '110.00', '220.00', '1.00'));

        $valuation = $this->today();
        self::assertSame(['1200', '279', '1479', 'DERIVED', 'POSITIONS', self::NOTE], [$valuation['securities'], $valuation['cash'], $valuation['total'], $valuation['source'], $valuation['detail'], $valuation['note']]);
        self::assertSame([[self::A, '12', '100', $this->day(-3), 'CURRENT']], $this->lines());
        self::assertSame('1479', $this->netWorthFigure());
    }

    public function testABuyOfANewInstrumentIsPricedFromTheTrade(): void
    {
        $this->baseWithPositions();

        $this->operation($this->buy(self::B, '3', '50.00', '150.00'));

        $valuation = $this->today();
        self::assertSame(['1150', '350', '1500'], [$valuation['securities'], $valuation['cash'], $valuation['total']]);
        self::assertSame([[self::A, '10', '100', $this->day(-3), 'CURRENT'], [self::B, '3', '50', $this->day(0), 'CURRENT']], $this->lines());
    }

    public function testASaleKeepsTheCarriedPriceAndSellingEverythingRemovesTheLine(): void
    {
        $this->baseWithPositions();

        $this->operation($this->sell('4', '120.00', '480.00', '2.00'));
        $valuation = $this->today();
        self::assertSame(['600', '978', '1578'], [$valuation['securities'], $valuation['cash'], $valuation['total']]);
        self::assertSame([[self::A, '6', '100', $this->day(-3), 'CURRENT']], $this->lines());

        $this->operation($this->sell('6', '120.00', '720.00'));
        $valuation = $this->today();
        self::assertSame(['0', '1698', '1698'], [$valuation['securities'], $valuation['cash'], $valuation['total']]);
        self::assertSame([], $this->lines());
    }

    /** @param array<string, mixed> $operation */
    #[\PHPUnit\Framework\Attributes\DataProvider('cashOnlyOperations')]
    public function testACashOnlyOperationRestatesTheCashAndKeepsTheLines(array $operation, string $cash, string $total): void
    {
        $this->baseWithPositions();

        $this->operation($operation);

        $valuation = $this->today();
        self::assertSame(['1000', $cash, $total], [$valuation['securities'], $valuation['cash'], $valuation['total']]);
        self::assertSame([[self::A, '10', '100', $this->day(-3), 'CURRENT']], $this->lines());
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function cashOnlyOperations(): iterable
    {
        yield 'dividend' => [['type' => 'DIVIDEND', 'tradeDate' => self::today0(), 'instrumentId' => self::A, 'grossAmount' => self::eur('20.00')], '520', '1520'];
        yield 'interest' => [['type' => 'INTEREST', 'tradeDate' => self::today0(), 'grossAmount' => self::eur('20.00')], '520', '1520'];
        yield 'fee' => [['type' => 'FEE', 'tradeDate' => self::today0(), 'fees' => self::eur('3.00')], '497', '1497'];
        yield 'tax' => [['type' => 'TAX', 'tradeDate' => self::today0(), 'taxes' => self::eur('4.00')], '496', '1496'];
        yield 'deposit' => [['type' => 'DEPOSIT', 'tradeDate' => self::today0(), 'grossAmount' => self::eur('100.00')], '600', '1600'];
        yield 'withdrawal' => [['type' => 'WITHDRAWAL', 'tradeDate' => self::today0(), 'grossAmount' => self::eur('100.00')], '400', '1400'];
    }

    public function testVoidingTheBuyRestatesTheBaseFigures(): void
    {
        $this->baseWithPositions();
        $buy = $this->operation($this->buy(self::B, '3', '50.00', '150.00'));

        $this->send('POST', '/api/v1/portfolio-operations/'.$buy.'/void', ['version' => 1, 'reason' => 'Erreur']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $valuation = $this->today();
        self::assertSame(['1000', '500', '1500'], [$valuation['securities'], $valuation['cash'], $valuation['total']]);
        self::assertSame([[self::A, '10', '100', $this->day(-3), 'CURRENT']], $this->lines());
        self::assertSame(1, $this->supersededToday());
    }

    public function testCorrectingTheBuyRestatesFromTheOperationsAsTheyNowStand(): void
    {
        $this->baseWithPositions();
        $buy = $this->operation($this->buy(self::A, '2', '110.00', '220.00', '1.00'));

        $this->send('POST', '/api/v1/portfolio-operations/'.$buy.'/correct', ['version' => 1, 'operation' => $this->buy(self::A, '5', '110.00', '550.00', '1.00')]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        $valuation = $this->today();
        self::assertSame(['1500', '-51', '1449'], [$valuation['securities'], $valuation['cash'], $valuation['total']]);
        self::assertSame([[self::A, '15', '100', $this->day(-3), 'CURRENT']], $this->lines());
    }

    public function testTwoBuysOfTheSameDayLeaveOneActiveValuation(): void
    {
        $this->baseWithPositions();

        $this->operation($this->buy(self::B, '3', '50.00', '150.00'));
        $this->operation($this->buy(self::B, '1', '60.00', '60.00'));

        $valuation = $this->today();
        self::assertSame(['1200', '290', '1490'], [$valuation['securities'], $valuation['cash'], $valuation['total']]);
        self::assertSame([[self::A, '10', '100', $this->day(-3), 'CURRENT'], [self::B, '4', '50', $this->day(0), 'CURRENT']], $this->lines());
        self::assertSame(1, $this->activeToday());
    }

    public function testAnObservedCashGapOfTheBaseIsKept(): void
    {
        $this->openingOperations();
        $this->valuation($this->day(-3), ['observedCash' => self::eur('520.00')]);

        $this->operation(['type' => 'FEE', 'tradeDate' => $this->day(0), 'fees' => self::eur('3.00')]);

        $valuation = $this->today();
        self::assertSame(['517', '1517', 'OBSERVED'], [$valuation['cash'], $valuation['total'], $valuation['source']]);
    }

    public function testACarriedPriceTurnsStaleInsteadOfCurrent(): void
    {
        $this->openingOperations(-12);
        $this->valuation($this->day(-10), []);

        $this->operation($this->buy(self::A, '1', '110.00', '110.00'));

        self::assertSame([[self::A, '11', '100', $this->day(-10), 'STALE']], $this->lines());
    }

    public function testABackDatedBuyRestatesOnlyTheValuationOfToday(): void
    {
        $this->baseWithPositions();

        $this->operation($this->buy(self::A, '2', '110.00', '220.00', '1.00', $this->day(-5)));

        self::assertSame('1479', $this->today()['total']);
        self::assertSame(1, $this->activeOn($this->day(-3)));
        self::assertSame('1500', $this->totalOn($this->day(-3)));
    }

    public function testACorrectionThatChangesNothingRecordsNoSecondValuation(): void
    {
        $this->baseWithPositions();
        $deposit = $this->operation(['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('100.00')]);
        $before = $this->countValuations();

        $this->send('POST', '/api/v1/portfolio-operations/'.$deposit.'/correct', ['version' => 1, 'operation' => ['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('100.00'), 'note' => 'Précisé']]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        self::assertSame($before, $this->countValuations());
        self::assertSame('1600', $this->today()['total']);
    }

    public function testNothingIsRecordedWithoutACompleteValuationToCarry(): void
    {
        $this->openingOperations();

        $this->operation($this->buy(self::A, '2', '110.00', '220.00'));

        self::assertSame(0, $this->countValuations());
    }

    public function testNothingIsRecordedWhenTheBaseCarriesAStatementTotal(): void
    {
        $this->openingOperations();
        $this->valuation($this->day(-3), ['observedTotal' => self::eur('1510.00')]);
        $before = $this->countValuations();

        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('100.00')]);

        self::assertSame($before, $this->countValuations());
    }

    public function testNothingIsRecordedWhenATotalBaseMeetsABuy(): void
    {
        $this->openingOperations();
        $this->send('POST', '/api/v1/accounts/'.self::PORTFOLIO.'/portfolio-valuations', ['valuedOn' => $this->day(-3), 'detail' => 'TOTAL', 'totalValue' => self::eur('1500.00')]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        $this->operation($this->buy(self::A, '2', '110.00', '220.00'));
        self::assertSame(1, $this->countValuations());

        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('100.00')]);
        self::assertSame(1, $this->countValuations());
    }

    public function testNothingIsRecordedWhenAnEarlierUnrelatedChangeHidesBehindTheOperation(): void
    {
        $this->baseWithPositions();
        $this->operation($this->buy(self::A, '1', '105.00', '105.00', null, $this->day(-1)));
        // Back to the situation where the earlier purchase never reached a valuation of the day.
        $this->connection->executeStatement('DELETE FROM portfolio_position_snapshots WHERE valuation_id IN (SELECT id FROM portfolio_valuations WHERE valued_on = :day)', ['day' => $this->day(0)]);
        $this->connection->executeStatement('DELETE FROM portfolio_valuations WHERE valued_on = :day', ['day' => $this->day(0)]);
        $before = $this->countValuations();

        $this->operation($this->buy(self::A, '1', '106.00', '106.00'));

        self::assertSame($before, $this->countValuations());
    }

    public function testNothingIsTouchedOnAnotherAccountOrWorkspace(): void
    {
        $this->baseWithPositions();
        $before = $this->connection->fetchOne('SELECT count(*) FROM portfolio_valuations WHERE account_id = :account', ['account' => self::OTHER_PORTFOLIO]);

        $this->operation($this->buy(self::A, '2', '110.00', '220.00'));

        self::assertSame($before, $this->connection->fetchOne('SELECT count(*) FROM portfolio_valuations WHERE account_id = :account', ['account' => self::OTHER_PORTFOLIO]));
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM portfolio_valuations WHERE workspace_id = :workspace', ['workspace' => WorkspaceFixture::OTHER_WORKSPACE]));
    }

    public function testARefusedOperationLeavesTheValuationUntouched(): void
    {
        $this->baseWithPositions();
        $before = $this->countValuations();

        $this->operation($this->sell('50', '120.00', '6000.00'), 422);

        self::assertSame($before, $this->countValuations());
    }

    public function testAValuationLimitReachedFailsTheWholeOperationWithAConflict(): void
    {
        $this->baseWithPositions();
        $this->connection->executeStatement(
            'INSERT INTO portfolio_valuations (workspace_id, '.ValuationRow::COLUMNS.') SELECT workspace_id, gen_random_uuid(), account_id, valued_on - gs, asset_code, cash_value, cash_scale, derived_cash_value, derived_cash_scale, cash_source,'
            .' securities_value_value, securities_value_scale, total_value_value, total_value_scale, observed_total_value, observed_total_scale, detail, completeness, source, note, NULL,'
            .' NULL, withdrawn, recorded_at, recorded_by, version FROM portfolio_valuations, generate_series(1, 4999) AS gs WHERE account_id = :account AND valued_on = :day',
            ['account' => self::PORTFOLIO, 'day' => $this->day(-3)],
        );
        $operations = $this->countOperations();
        $valuations = $this->countValuations();

        $this->operation($this->buy(self::A, '2', '110.00', '220.00'), 409);

        self::assertSame('valuation_conflict', $this->problemCode());
        self::assertSame($operations, $this->countOperations());
        self::assertSame($valuations, $this->countValuations());
    }

    public function testCorrectingAPurchaseTakesTheNewTradePriceOfAnInstrumentPricedFromIt(): void
    {
        $this->baseWithPositions();
        $buy = $this->operation($this->buy(self::B, '3', '50.00', '150.00'));

        $this->send('POST', '/api/v1/portfolio-operations/'.$buy.'/correct', ['version' => 1, 'operation' => $this->buy(self::B, '3', '60.00', '180.00')]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        self::assertSame([[self::A, '10', '100', $this->day(-3), 'CURRENT'], [self::B, '3', '60', $this->day(0), 'CURRENT']], $this->lines());
        self::assertSame(['1180', '320', '1500'], [$this->today()['securities'], $this->today()['cash'], $this->today()['total']]);
    }

    public function testACashOnlyOperationOnATotalBaseKeepsATotalValuation(): void
    {
        $this->totalBase();

        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('100.00')]);

        $valuation = $this->today();
        self::assertSame(['TOTAL', '1600', '600', self::NOTE], [$valuation['detail'], $valuation['total'], $valuation['cash'], $valuation['note']]);
    }

    public function testASaleOnATotalBaseRecordsNoValuation(): void
    {
        $this->totalBase();

        $this->operation($this->sell('4', '120.00', '480.00'));

        self::assertNull($this->valuationOn($this->day(0)));
    }

    public function testAWithdrawnBaseIsNotCarried(): void
    {
        $this->baseWithPositions();
        $this->connection->executeStatement('UPDATE portfolio_valuations SET withdrawn = true, superseded_at = now() WHERE account_id = :account', ['account' => self::PORTFOLIO]);

        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('100.00')]);

        self::assertNull($this->valuationOn($this->day(0)));
    }

    public function testAnArchivedOrClosedAccountRefusesTheOperationAndKeepsItsValuations(): void
    {
        $this->baseWithPositions();
        $valuations = $this->countValuations();
        $this->connection->executeStatement('UPDATE account_financial_accounts SET archived_at = now() WHERE id = :id', ['id' => self::PORTFOLIO]);
        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('100.00')], 422);
        self::assertSame('portfolio_account_inactive', $this->problemCode());

        $this->connection->executeStatement('UPDATE account_financial_accounts SET archived_at = NULL, closed_on = :day WHERE id = :id', ['id' => self::PORTFOLIO, 'day' => $this->day(-1)]);
        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('100.00')], 422);

        self::assertSame($valuations, $this->countValuations());
    }

    public function testAClosedMonthRefusesTheOperationAndTheVoidAndChangesNothing(): void
    {
        $this->baseWithPositions();
        $deposit = $this->operation(['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('100.00')]);
        $operations = $this->countOperations();
        $valuations = $this->countValuations();
        $this->connection->insert('account_period_closures', [
            'id' => '00000000-0000-7000-8000-0000000000c9', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'year' => (int) substr($this->day(0), 0, 4), 'month' => (int) substr($this->day(0), 5, 2),
            'closed_at' => '2026-01-02 10:00:00+00', 'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);

        $this->operation($this->buy(self::A, '1', '110.00', '110.00'), 409);
        $this->send('POST', '/api/v1/portfolio-operations/'.$deposit.'/void', ['version' => 1]);
        self::assertResponseStatusCodeSame(409);
        $this->send('POST', '/api/v1/portfolio-operations/'.$deposit.'/correct', ['version' => 1, 'operation' => ['type' => 'DEPOSIT', 'tradeDate' => $this->day(0), 'grossAmount' => self::eur('50.00')]]);
        self::assertResponseStatusCodeSame(409);

        self::assertSame([$operations, $valuations], [$this->countOperations(), $this->countValuations()]);
        self::assertSame('1600', $this->today()['total']);
    }

    public function testARetriedRecordWithTheSameKeyRecordsNoSecondValuation(): void
    {
        $this->baseWithPositions();
        $body = json_encode($this->buy(self::A, '2', '110.00', '220.00'), JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'key-refresh-0000000001'];

        $this->client->request('POST', '/api/v1/accounts/'.self::PORTFOLIO.'/portfolio-operations', server: $server, content: $body);
        self::assertResponseStatusCodeSame(201);
        $valuations = $this->countValuations();
        $this->client->request('POST', '/api/v1/accounts/'.self::PORTFOLIO.'/portfolio-operations', server: $server, content: $body);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($valuations, $this->countValuations());
        self::assertSame(1, $this->activeToday());
    }

    private function totalBase(): void
    {
        $this->openingOperations();
        $this->send('POST', '/api/v1/accounts/'.self::PORTFOLIO.'/portfolio-valuations', ['valuedOn' => $this->day(-3), 'detail' => 'TOTAL', 'totalValue' => self::eur('1500.00')]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    private function countOperations(): int
    {
        return self::integer($this->connection->fetchOne('SELECT count(*) FROM portfolio_operations WHERE account_id = :account', ['account' => self::PORTFOLIO]));
    }

    private function problemCode(): string
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? self::text($decoded['code'] ?? '') : '';
    }

    /** @return array<string, mixed> */
    private static function eur(string $value): array
    {
        return ['value' => $value, 'assetCode' => 'EUR'];
    }

    private static function today0(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->modify($offset.' days')->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function buy(string $instrument, string $quantity, string $price, string $gross, ?string $fees = null, ?string $on = null): array
    {
        return array_filter([
            'type' => 'BUY', 'tradeDate' => $on ?? $this->day(0), 'instrumentId' => $instrument, 'quantity' => $quantity,
            'unitPrice' => $price, 'grossAmount' => self::eur($gross), 'fees' => null === $fees ? null : self::eur($fees),
        ], static fn (mixed $value): bool => null !== $value);
    }

    /** @return array<string, mixed> */
    private function sell(string $quantity, string $price, string $gross, ?string $fees = null): array
    {
        return array_filter([
            'type' => 'SELL', 'tradeDate' => $this->day(0), 'instrumentId' => self::A, 'quantity' => $quantity,
            'unitPrice' => $price, 'grossAmount' => self::eur($gross), 'fees' => null === $fees ? null : self::eur($fees),
        ], static fn (mixed $value): bool => null !== $value);
    }

    /** A x10 bought at 100.00 and 500.00 of cash left, the account valued by position three days ago. */
    private function baseWithPositions(): void
    {
        $this->openingOperations();
        $this->valuation($this->day(-3), []);
    }

    private function openingOperations(int $days = -6): void
    {
        $this->operation(['type' => 'DEPOSIT', 'tradeDate' => $this->day($days), 'grossAmount' => self::eur('1500.00')]);
        $this->operation($this->buy(self::A, '10', '100.00', '1000.00', null, $this->day($days)));
    }

    /** @param array<string, mixed> $extra */
    private function valuation(string $on, array $extra): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::PORTFOLIO.'/portfolio-valuations', [
            'valuedOn' => $on, 'detail' => 'POSITIONS', 'prices' => [['instrumentId' => self::A, 'price' => '100.00', 'priceDate' => $on]],
        ] + $extra);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, mixed> $body */
    private function operation(array $body, int $status = 201): string
    {
        $this->send('POST', '/api/v1/accounts/'.self::PORTFOLIO.'/portfolio-operations', $body);
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return 201 === $status && is_array($decoded) && is_string($decoded['id'] ?? null) ? $decoded['id'] : '';
    }

    /** @return array<string, string|null> */
    private function today(): array
    {
        return $this->valuationOn($this->day(0)) ?? self::fail('No valuation of today.');
    }

    /** @return array<string, string|null>|null */
    private function valuationOn(string $day): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT securities_value_value AS securities, cash_value AS cash, total_value_value AS total, cash_source AS source, detail, note FROM portfolio_valuations
             WHERE account_id = :account AND valued_on = :day AND superseded_at IS NULL',
            ['account' => self::PORTFOLIO, 'day' => $day],
        );
        if (false === $row) {
            return null;
        }
        $typed = [];
        foreach ($row as $key => $value) {
            $typed[(string) $key] = null === $value ? null : (in_array($key, ['securities', 'cash', 'total'], true) ? self::trim(self::text($value)) : self::text($value));
        }

        return $typed;
    }

    /** @return list<array{string, string, string, string, string}> */
    private function lines(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.instrument_id, s.quantity_value, s.unit_price_value, s.price_date::text AS price_date, s.price_quality FROM portfolio_position_snapshots s
             JOIN portfolio_valuations v ON v.id = s.valuation_id WHERE v.account_id = :account AND v.valued_on = :day AND v.superseded_at IS NULL ORDER BY s.instrument_id',
            ['account' => self::PORTFOLIO, 'day' => $this->day(0)],
        );

        return array_map(static fn (array $row): array => [
            self::text($row['instrument_id']), self::trim(self::text($row['quantity_value'])), self::trim(self::text($row['unit_price_value'])), self::text($row['price_date']), self::text($row['price_quality']),
        ], $rows);
    }

    private function totalOn(string $day): ?string
    {
        return $this->valuationOn($day)['total'] ?? null;
    }

    private function activeOn(string $day): int
    {
        return self::integer($this->connection->fetchOne('SELECT count(*) FROM portfolio_valuations WHERE account_id = :account AND valued_on = :day AND superseded_at IS NULL', ['account' => self::PORTFOLIO, 'day' => $day]));
    }

    private function activeToday(): int
    {
        return $this->activeOn($this->day(0));
    }

    private function supersededToday(): int
    {
        return self::integer($this->connection->fetchOne('SELECT count(*) FROM portfolio_valuations WHERE account_id = :account AND valued_on = :day AND superseded_at IS NOT NULL', ['account' => self::PORTFOLIO, 'day' => $this->day(0)]));
    }

    private function countValuations(): int
    {
        return self::integer($this->connection->fetchOne('SELECT count(*) FROM portfolio_valuations WHERE account_id = :account', ['account' => self::PORTFOLIO]));
    }

    private function netWorthFigure(): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT amount_value FROM account_balance_snapshots WHERE account_id = :account AND as_of = :day ORDER BY recorded_at DESC LIMIT 1',
            ['account' => self::PORTFOLIO, 'day' => $this->day(0)],
        );

        return false === $value ? null : self::trim(self::text($value));
    }

    private static function text(mixed $value): string
    {
        self::assertTrue(is_string($value) || is_int($value));

        return (string) $value;
    }

    private static function integer(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    private static function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }
}
