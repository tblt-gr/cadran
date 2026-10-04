<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Accounts\Application\AssessPeriodClosing;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PortfolioValuationControllerTest extends WebTestCase
{
    use ClosesPeriods;

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

    public function testATotalValuationDerivesSecuritiesPublishesTheTotalAndKeepsLinesUnpriced(): void
    {
        $this->seedHoldings();

        $valuation = $this->recordTotal('2026-03-31', '3664.70');

        self::assertSame('TOTAL', $valuation['detail']);
        self::assertSame('3664.70', self::amount($valuation, 'totalValue'));
        self::assertSame('1193.28', self::amount($valuation, 'cash'));
        self::assertSame('1193.28', self::amount($valuation, 'derivedCash'));
        self::assertSame('2471.42', self::amount($valuation, 'securitiesValue'));
        self::assertSame('COMPLETE', $valuation['completeness']);
        self::assertSame('NOT_DETAILED', $valuation['priceQuality']);
        self::assertSame([], $valuation['flags']);
        $lines = self::lines($valuation);
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            self::assertNull($line['unitPrice']);
            self::assertNull($line['marketValue']);
            self::assertSame('NOT_DETAILED', $line['priceQuality']);
        }
        self::assertSame('10', $lines[0]['quantity']);
        self::assertSame('2312.00', self::amount($lines[0], 'averageCostBasis'));

        $published = $this->connection->fetchAssociative("SELECT amount_literal, reconciliation_status FROM account_balance_snapshots WHERE account_id = ? AND source = 'CALCULATED' AND superseded_at IS NULL", [self::ACCOUNT]);
        self::assertSame(['amount_literal' => '3664.70', 'reconciliation_status' => 'RECONCILED'], $published);
    }

    public function testAPositionsValuationStoresExactMarketValuesAndAcceptsTheObservedTotalInsideTheTolerance(): void
    {
        $this->seedHoldings();

        $valuation = $this->recordPositions('2026-03-31', [
            ['instrumentId' => self::ETF, 'price' => '231.4521', 'priceDate' => '2026-03-31'],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-31'],
        ], ['observedTotal' => $this->eur('3664.70')]);

        $lines = self::lines($valuation);
        self::assertSame('2314.521', self::amount($lines[0], 'marketValue'));
        self::assertSame('156.90', self::amount($lines[1], 'marketValue'));
        self::assertSame('2471.421', self::amount($valuation, 'securitiesValue'));
        self::assertSame('3664.701', self::amount($valuation, 'totalValue'));
        self::assertSame('-0.001', self::amount($valuation, 'observedTotalDifference'));
        self::assertSame('0.015', self::amount($valuation, 'tolerance'));
        self::assertSame([], $valuation['flags']);
        self::assertSame('CURRENT', $valuation['priceQuality']);
        self::assertSame(0, $lines[0]['priceAgeDays']);
        self::assertSame('3664.701', $this->connection->fetchOne("SELECT amount_literal FROM account_balance_snapshots WHERE account_id = ? AND source = 'CALCULATED' AND superseded_at IS NULL", [self::ACCOUNT]));
    }

    public function testAnObservedTotalBeyondTheToleranceIsStoredAndFlagged(): void
    {
        $this->seedHoldings();

        $valuation = $this->recordPositions('2026-03-31', $this->currentPrices('2026-03-31'), ['observedTotal' => $this->eur('3700.00')]);

        self::assertSame(['OBSERVED_TOTAL_DIFFERENCE'], $valuation['flags']);
        self::assertSame('37.82', self::amount($valuation, 'observedTotalDifference'));
    }

    public function testAnObservedCashDifferentFromTheDerivedCashReplacesItAndIsFlagged(): void
    {
        $this->seedHoldings();

        $valuation = $this->recordTotal('2026-03-31', '3664.70', ['observedCash' => $this->eur('1200.00')]);

        self::assertSame('1200.00', self::amount($valuation, 'cash'));
        self::assertSame('1193.28', self::amount($valuation, 'derivedCash'));
        self::assertSame('OBSERVED', $valuation['cashSource']);
        self::assertSame('6.72', self::amount($valuation, 'cashDifference'));
        self::assertSame('2464.70', self::amount($valuation, 'securitiesValue'));
        self::assertSame(['CASH_DIFFERENCE'], $valuation['flags']);
    }

    public function testATotalLowerThanTheDerivedCashGivesANegativeSecuritiesValueAndItsFlag(): void
    {
        $this->seedHoldings();

        $valuation = $this->recordTotal('2026-03-31', '1000.00');

        self::assertSame('-193.28', self::amount($valuation, 'securitiesValue'));
        self::assertSame(['NEGATIVE_SECURITIES_VALUE'], $valuation['flags']);
    }

    public function testAZeroTotalWithNoHoldingsIsAValidCompleteValuation(): void
    {
        $valuation = $this->recordTotal('2026-03-31', '0');

        self::assertSame('0', self::amount($valuation, 'totalValue'));
        self::assertSame('0', self::amount($valuation, 'securitiesValue'));
        self::assertSame([], self::lines($valuation));
    }

    public function testANullPriceMakesTheValuationPartialPublishesNothingAndKeepsNoZero(): void
    {
        $this->seedHoldings();

        $valuation = $this->recordPositions('2026-03-31', [
            ['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-31'],
        ]);

        self::assertSame('PARTIAL', $valuation['completeness']);
        self::assertNull($valuation['totalValue']);
        self::assertNull($valuation['securitiesValue']);
        self::assertSame('MISSING', $valuation['priceQuality']);
        $lines = self::lines($valuation);
        self::assertNull($lines[0]['marketValue']);
        self::assertSame('MISSING', $lines[0]['priceQuality']);
        self::assertSame('156.90', self::amount($lines[1], 'marketValue'));
        self::assertSame(0, $this->rows('account_balance_snapshots', "source = 'CALCULATED'"));
    }

    public function testSupersedingACompleteValuationByAPartialOneWithdrawsThePublishedFigure(): void
    {
        $this->seedHoldings();
        $complete = $this->recordTotal('2026-03-31', '3664.70');
        self::assertSame(1, $this->rows('account_balance_snapshots', "source = 'CALCULATED' AND superseded_at IS NULL"));

        $this->recordPositions('2026-03-31', [
            ['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-31'],
        ], ['version' => $complete['version']]);

        self::assertSame(0, $this->rows('account_balance_snapshots', "source = 'CALCULATED' AND superseded_at IS NULL"));
        self::assertSame(1, $this->rows('account_balance_snapshots', "source = 'CALCULATED'"));
        self::assertSame(1, $this->rows('portfolio_valuations', 'superseded_at IS NULL'));
    }

    public function testAPriceFiveDaysOldIsCurrentAndSixDaysOldIsStaleWithTheFlag(): void
    {
        $this->seedHoldings();

        $valuation = $this->recordPositions('2026-03-31', [
            ['instrumentId' => self::ETF, 'price' => '231.20', 'priceDate' => '2026-03-26'],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-25'],
        ]);

        $lines = self::lines($valuation);
        self::assertSame('CURRENT', $lines[0]['priceQuality']);
        self::assertSame(5, $lines[0]['priceAgeDays']);
        self::assertSame('STALE', $lines[1]['priceQuality']);
        self::assertSame(6, $lines[1]['priceAgeDays']);
        self::assertSame('STALE', $valuation['priceQuality']);
        self::assertSame(['STALE_PRICES'], $valuation['flags']);
        self::assertSame('COMPLETE', $valuation['completeness']);
    }

    public function testQuantityAndPriceAtTwelveDecimalsAreStoredExactlyOnTwentyFour(): void
    {
        $this->portfolio->account('00000000-0000-7000-8000-0000000000d9', label: 'Précision');
        $this->send('POST', '/api/v1/accounts/00000000-0000-7000-8000-0000000000d9/portfolio-operations', [
            'type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-03-02', 'instrumentId' => self::STOCK, 'quantity' => '1.123456789012',
        ]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        $this->send('POST', '/api/v1/accounts/00000000-0000-7000-8000-0000000000d9/portfolio-valuations', [
            'valuedOn' => '2026-03-31', 'detail' => 'POSITIONS',
            'prices' => [['instrumentId' => self::STOCK, 'price' => '12.123456789012', 'priceDate' => '2026-03-31']],
        ]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $line = self::lines($this->decode())[0];

        self::assertSame('13.620179835909153483936144', self::amount($line, 'marketValue'));
        self::assertSame('12.123456789012', $line['unitPrice']);
    }

    public function testAPriceDeeperThanTheInstrumentPrecisionIsRefusedOnItsRow(): void
    {
        $this->seedHoldings();

        $this->postValuation(['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [
            ['instrumentId' => self::ETF, 'price' => '231.45211', 'priceDate' => '2026-03-31'],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-31'],
        ]]);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->decode();
        self::assertSame('price_precision_exceeded', $problem['code']);
        self::assertSame('/prices/0/price', $problem['field']);
        self::assertSame(0, $this->rows('portfolio_valuations'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): iterable
    {
        $price = static fn (string $id, ?string $price, ?string $date): array => ['instrumentId' => $id, 'price' => $price, 'priceDate' => $date];
        $both = [$price(self::ETF, '231.20', '2026-03-31'), $price(self::STOCK, '52.30', '2026-03-31')];

        yield 'unknown detail' => [['valuedOn' => '2026-03-31', 'detail' => 'SUMMARY'], 'invalid_valuation_detail'];
        yield 'total with prices' => [['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => '10', 'assetCode' => 'EUR'], 'prices' => []], 'valuation_field_not_allowed'];
        yield 'total with observed total' => [['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => '10', 'assetCode' => 'EUR'], 'observedTotal' => ['value' => '10', 'assetCode' => 'EUR']], 'valuation_field_not_allowed'];
        yield 'positions with total' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'totalValue' => ['value' => '10', 'assetCode' => 'EUR'], 'prices' => $both], 'valuation_field_not_allowed'];
        yield 'total missing' => [['valuedOn' => '2026-03-31', 'detail' => 'TOTAL'], 'invalid_valuation_total'];
        yield 'negative total' => [['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => '-1.00', 'assetCode' => 'EUR']], 'invalid_valuation_total'];
        yield 'total beyond storable width' => [['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => '100000000000000000000000000.00', 'assetCode' => 'EUR']], 'invalid_valuation_total'];
        yield 'total in another asset' => [['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => '10', 'assetCode' => 'USD']], 'operation_asset_mismatch'];
        yield 'observed cash in another asset' => [['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => '10', 'assetCode' => 'EUR'], 'observedCash' => ['value' => '1', 'assetCode' => 'USD']], 'operation_asset_mismatch'];
        yield 'date in the future' => [['valuedOn' => '2999-01-01', 'detail' => 'TOTAL', 'totalValue' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_valuation_date'];
        yield 'date before opening' => [['valuedOn' => '2025-12-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_valuation_date'];
        yield 'not a date' => [['valuedOn' => '2026-02-30', 'detail' => 'TOTAL', 'totalValue' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_valuation_date'];
        yield 'missing price entry' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [$price(self::ETF, '231.20', '2026-03-31')]], 'valuation_price_entry_missing'];
        yield 'no prices at all' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS'], 'valuation_price_entry_missing'];
        yield 'instrument not held' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [...$both, $price('00000000-0000-7000-8000-000000000b09', '1', '2026-03-31')]], 'unknown_valuation_instrument'];
        yield 'duplicate entry' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [...$both, $both[0]]], 'invalid_valuation_price'];
        yield 'zero price' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [$price(self::ETF, '0', '2026-03-31'), $both[1]]], 'invalid_valuation_price'];
        yield 'negative price' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [$price(self::ETF, '-5', '2026-03-31'), $both[1]]], 'invalid_valuation_price'];
        yield 'malformed price' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [$price(self::ETF, '1,5', '2026-03-31'), $both[1]]], 'invalid_valuation_price'];
        yield 'price without date' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [$price(self::ETF, '231.20', null), $both[1]]], 'invalid_price_date'];
        yield 'price dated after the valuation' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [$price(self::ETF, '231.20', '2026-04-01'), $both[1]]], 'invalid_price_date'];
        yield 'date without price' => [['valuedOn' => '2026-03-31', 'detail' => 'POSITIONS', 'prices' => [$price(self::ETF, null, '2026-03-31'), $both[1]]], 'invalid_price_date'];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
    public function testInvalidBodiesAreRefusedWithTheirCodeAndWriteNothing(array $body, string $code): void
    {
        $this->seedHoldings();

        $this->postValuation($body);

        self::assertResponseStatusCodeSame(422, (string) $this->client->getResponse()->getContent());
        self::assertSame($code, $this->decode()['code']);
        self::assertSame(0, $this->rows('portfolio_valuations'));
        self::assertSame(0, $this->rows('account_balance_snapshots'));
    }

    public function testUndeclaredFieldsAndWrongTypesAreRefused(): void
    {
        $this->postValuation(['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => '10', 'extra' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_valuation_payload', $this->decode()['code']);

        $this->postValuation(['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => 10, 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_valuation_payload', $this->decode()['code']);

        $this->postValuation(['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => '10', 'assetCode' => 'EUR'], 'note' => '']);
        self::assertResponseStatusCodeSame(422);
        $this->postValuation(['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => ['value' => '10', 'assetCode' => 'EUR'], 'note' => str_repeat('a', 201)]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testASecondValuationOfTheSameDayNeedsTheCurrentVersion(): void
    {
        $this->seedHoldings();
        $first = $this->recordTotal('2026-03-31', '3664.70');

        $this->postValuation($this->totalBody('2026-03-31', '3700.00'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('valuation_exists', $this->decode()['code']);

        $this->postValuation($this->totalBody('2026-03-31', '3700.00') + ['version' => 99]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_valuation_version', $this->decode()['code']);

        $this->postValuation($this->totalBody('2026-04-01', '3700.00') + ['version' => 1]);
        self::assertResponseStatusCodeSame(409, 'a version where nothing exists is stale');
        self::assertSame('stale_valuation_version', $this->decode()['code']);

        $second = $this->recordTotal('2026-03-31', '3700.00', ['version' => $first['version']]);

        self::assertSame($first['id'], $second['supersedesId']);
        self::assertSame(2, $this->rows('portfolio_valuations'));
        self::assertSame(1, $this->rows('portfolio_valuations', 'superseded_at IS NULL'));
        self::assertSame('3664.70', $this->connection->fetchOne('SELECT total_value_value::numeric(20,2)::text FROM portfolio_valuations WHERE id = ?', [$first['id']]));
        self::assertSame(2, $this->connection->fetchOne('SELECT version FROM portfolio_valuations WHERE id = ?', [$first['id']]));
        self::assertSame(2, $this->rows('account_balance_snapshots', "source = 'CALCULATED'"));
        self::assertSame(1, $this->rows('account_balance_snapshots', "source = 'CALCULATED' AND superseded_at IS NULL"));
        self::assertSame('3700.00', $this->connection->fetchOne("SELECT amount_literal FROM account_balance_snapshots WHERE source = 'CALCULATED' AND superseded_at IS NULL"));
        $events = $this->connection->fetchFirstColumn("SELECT event_type FROM audit_events WHERE entity_type = 'portfolio_valuation' ORDER BY occurred_at, id");
        self::assertEqualsCanonicalizing(['portfolio_valuation.recorded', 'portfolio_valuation.superseded', 'portfolio_valuation.recorded'], $events);
    }

    public function testTheAuditTrailNamesNoPriceQuantityOrAmount(): void
    {
        $this->seedHoldings();
        $this->recordPositions('2026-03-31', $this->currentPrices('2026-03-31'), ['note' => 'Relevé de mars']);

        $trail = implode(' ', array_map(static fn (mixed $row): string => is_string($row) ? $row : '', $this->connection->fetchFirstColumn("SELECT concat(before_json::text, after_json::text) FROM audit_events WHERE entity_type = 'portfolio_valuation'")));

        self::assertStringContainsString('POSITIONS', $trail);
        foreach (['231.20', '52.30', '3664', '2314', '1193', 'Relevé'] as $secret) {
            self::assertStringNotContainsString($secret, $trail);
        }
    }

    public function testTheDraftPrefillsEachLineWithItsLastKnownPriceAndOrigin(): void
    {
        $this->seedHoldings();

        $draft = $this->draft('2026-03-31');

        self::assertSame('2026-03-31', $draft['valuedOn']);
        self::assertSame('1193.28', self::amount($draft, 'derivedCash'));
        self::assertSame('TOTAL', $draft['suggestedDetail']);
        self::assertNull($draft['lastTotal']);
        self::assertNull($draft['currentValuation']);
        $positions = self::lines($draft);
        self::assertSame('ETF Monde Démo', self::map($positions[0]['instrument'])['name']);
        self::assertSame('10', $positions[0]['quantity']);
        self::assertSame('231.20', $positions[0]['lastPrice']);
        self::assertSame('2026-03-03', $positions[0]['lastPriceDate']);
        $origin = self::map($positions[0]['lastPriceOrigin']);
        self::assertSame('OPERATION', $origin['kind']);
        self::assertSame('2026-03-03', $origin['date']);
        self::assertSame('52.30', $positions[1]['lastPrice']);
    }

    public function testASameDayValuationPriceWinsOverAnOperationAndALaterPriceIsIgnored(): void
    {
        $this->seedHoldings();
        $valuation = $this->recordPositions('2026-03-03', [
            ['instrumentId' => self::ETF, 'price' => '232.00', 'priceDate' => '2026-03-03'],
            ['instrumentId' => self::STOCK, 'price' => '53.00', 'priceDate' => '2026-03-03'],
        ]);

        $same = self::lines($this->draft('2026-03-31'));
        self::assertSame('232.00', $same[0]['lastPrice'], 'a valuation price wins on the same date');
        self::assertSame('VALUATION', self::map($same[0]['lastPriceOrigin'])['kind']);
        self::assertSame($valuation['id'], self::map($same[0]['lastPriceOrigin'])['id']);

        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-10', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '240.00', 'grossAmount' => $this->eur('240.00')]);
        $newer = self::lines($this->draft('2026-03-31'));
        self::assertSame('240.00', $newer[0]['lastPrice'], 'a newer operation price wins');
        self::assertSame('2026-03-10', $newer[0]['lastPriceDate']);

        $earlier = self::lines($this->draft('2026-03-05'));
        self::assertSame('232.00', $earlier[0]['lastPrice'], 'a price after the valuation date is ignored');
    }

    public function testTheDraftHasNoPriceWhenNoneIsKnownAndExposesTheCurrentValuationAndLastTotal(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-01', 'grossAmount' => $this->eur('100.00')]);
        $this->record(['type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-03-02', 'instrumentId' => self::ETF, 'quantity' => '2']);
        $first = $this->recordTotal('2026-03-15', '500.00');

        $draft = $this->draft('2026-03-31');
        $line = self::lines($draft)[0];
        self::assertNull($line['lastPrice']);
        self::assertNull($line['lastPriceDate']);
        self::assertNull($line['lastPriceOrigin']);
        self::assertSame('TOTAL', $draft['suggestedDetail']);
        self::assertSame('500.00', self::amount(self::map($draft['lastTotal']), 'value'));
        self::assertSame('2026-03-15', self::map($draft['lastTotal'])['valuedOn']);

        $replace = $this->draft('2026-03-15');
        self::assertSame(['id' => $first['id'], 'version' => 1], $replace['currentValuation']);
    }

    public function testTheDraftRefusesAnInvalidDateAndAForeignAccount(): void
    {
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations/draft?valuedOn=2026-02-30');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_valuation_date', $this->decode()['code']);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations/draft');
        self::assertResponseStatusCodeSame(422);

        $this->get('/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-valuations/draft?valuedOn=2026-03-31');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnOperationRecordedOnOrBeforeTheValuationDateAfterwardsFlagsPositionsChanged(): void
    {
        $this->seedHoldings();
        $valuation = $this->recordTotal('2026-03-31', '3664.70');

        $this->record(['type' => 'BUY', 'tradeDate' => '2026-04-10', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '240.00', 'grossAmount' => $this->eur('240.00')]);
        self::assertSame([], $this->show(self::string($valuation['id']))['flags'], 'a buy after the valuation date changes nothing');

        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-20', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '240.00', 'grossAmount' => $this->eur('240.00')]);
        self::assertSame(['POSITIONS_CHANGED'], $this->show(self::string($valuation['id']))['flags']);
        self::assertSame('3664.70', self::amount($this->show(self::string($valuation['id'])), 'totalValue'), 'nothing is rewritten');

        $list = $this->list(self::ACCOUNT);
        self::assertSame(['POSITIONS_CHANGED'], self::map(self::items($list)[0])['flags']);
    }

    public function testAVoidedOperationAfterAValuationFlagsItToo(): void
    {
        $this->seedHoldings();
        $valuation = $this->recordTotal('2026-03-31', '3664.70');
        $stockBuy = $this->connection->fetchOne("SELECT id FROM portfolio_operations WHERE instrument_id = ? AND operation_type = 'BUY'", [self::STOCK]);
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($stockBuy).'/void', ['version' => 1, 'reason' => 'Erreur']);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['POSITIONS_CHANGED'], $this->show(self::string($valuation['id']))['flags']);
    }

    public function testTheListIsNewestFirstPagedAndFilteredByRange(): void
    {
        $this->recordTotal('2026-01-31', '10.00');
        $this->recordTotal('2026-02-28', '20.00');
        $this->recordTotal('2026-03-31', '30.00');

        $page = $this->list(self::ACCOUNT, '?perPage=2');
        self::assertSame(['items', 'page', 'perPage', 'total'], array_keys($page));
        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['perPage']);
        self::assertSame(['2026-03-31', '2026-02-28'], array_column(array_map(self::map(...), self::items($page)), 'valuedOn'));
        $summary = self::map(self::items($page)[0]);
        self::assertSame(['id', 'accountId', 'valuedOn', 'detail', 'source', 'totalValue', 'cash', 'securitiesValue', 'completeness', 'priceQuality', 'flags', 'version'], array_keys($summary));

        $range = $this->list(self::ACCOUNT, '?from=2026-02-01&to=2026-03-01');
        self::assertSame(['2026-02-28'], array_column(array_map(self::map(...), self::items($range)), 'valuedOn'));

        $second = $this->list(self::ACCOUNT, '?perPage=2&page=2');
        self::assertSame(['2026-01-31'], array_column(array_map(self::map(...), self::items($second)), 'valuedOn'));

        foreach (['?perPage=0', '?perPage=101', '?page=0', '?from=2026-03-01&to=2026-02-01', '?from=bad'] as $query) {
            $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations'.$query);
            self::assertResponseStatusCodeSame(422, $query);
        }
    }

    public function testASupersededValuationStaysReadableButLeavesTheList(): void
    {
        $first = $this->recordTotal('2026-03-31', '10.00');
        $this->recordTotal('2026-03-31', '20.00', ['version' => $first['version']]);

        $old = $this->show(self::string($first['id']));
        self::assertNotNull($old['supersededAt']);
        self::assertSame('10.00', self::amount($old, 'totalValue'));
        self::assertSame([], $old['flags']);
        self::assertSame(1, self::map($this->list(self::ACCOUNT))['total']);
    }

    public function testWithdrawingSupersedesWithoutReplacementAndRetiresThePublishedFigure(): void
    {
        $valuation = $this->recordTotal('2026-03-31', '10.00');

        $this->send('POST', '/api/v1/portfolio-valuations/'.self::string($valuation['id']).'/withdraw', ['version' => 99]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_valuation_version', $this->decode()['code']);

        $this->send('POST', '/api/v1/portfolio-valuations/'.self::string($valuation['id']).'/withdraw', ['version' => $valuation['version']]);
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        self::assertNotNull($this->decode()['supersededAt']);

        self::assertSame(0, $this->rows('portfolio_valuations', 'superseded_at IS NULL'));
        self::assertSame(1, $this->rows('portfolio_valuations', 'withdrawn'));
        self::assertSame(0, $this->rows('account_balance_snapshots', "source = 'CALCULATED' AND superseded_at IS NULL"));
        self::assertSame(1, $this->rows('audit_events', "event_type = 'portfolio_valuation.withdrawn'"));
        self::assertSame(0, $this->rows('portfolio_valuations', "id = '".self::string($valuation['id'])."' AND total_value_value IS NULL"));

        $this->send('POST', '/api/v1/portfolio-valuations/'.self::string($valuation['id']).'/withdraw', ['version' => 2]);
        self::assertResponseStatusCodeSame(409, 'a withdrawn valuation cannot be withdrawn again');

        $this->recordTotal('2026-03-31', '15.00');
        self::assertSame(1, $this->rows('portfolio_valuations', 'superseded_at IS NULL'));
    }

    public function testManualBalancesAndReconciliationAreRefusedOnAPortfolioModeAccount(): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/balances', ['asOf' => '2026-03-31', 'amount' => '10.00', 'amountAssetCode' => 'EUR', 'comment' => null, 'version' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('portfolio_account_valued_by_portfolio', $this->decode()['code']);

        $this->send('POST', '/api/v1/accounts/'.self::SNAPSHOTS_ACCOUNT.'/balances', ['asOf' => '2026-03-31', 'amount' => '10.00', 'amountAssetCode' => 'EUR', 'comment' => null, 'version' => null]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $snapshot = $this->decode();

        $this->connection->update('account_financial_accounts', ['valuation_mode' => 'PORTFOLIO'], ['id' => self::SNAPSHOTS_ACCOUNT]);
        $this->send('POST', '/api/v1/accounts/'.self::SNAPSHOTS_ACCOUNT.'/reconciliation', [
            'snapshotId' => $snapshot['id'], 'snapshotVersion' => 1, 'periodStart' => '2026-03-01', 'resolution' => 'MATCH',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('portfolio_account_valued_by_portfolio', $this->decode()['code']);
    }

    public function testTheMonthClosesWithACompleteValuationAndIsBlockedWithoutOne(): void
    {
        $assess = self::getContainer()->get(AssessPeriodClosing::class);
        self::assertInstanceOf(AssessPeriodClosing::class, $assess);
        $this->connection->update('account_financial_accounts', ['archived_at' => '2026-01-02 00:00:00+00'], ['id' => self::SNAPSHOTS_ACCOUNT]);
        $march = CalendarMonth::containing(new \DateTimeImmutable('2026-03-15'));

        self::assertCount(1, $assess(WorkspaceFixture::own(), $march), 'no valuation, the month is blocked');

        $this->recordTotal('2026-03-31', '10.00');
        self::assertSame([], $assess(WorkspaceFixture::own(), $march));
    }

    public function testAClosedMonthRefusesRecordingAndWithdrawing(): void
    {
        $valuation = $this->recordTotal('2026-03-31', '10.00');
        $this->closeMonthInDatabase($this->connection, 2026, 3);

        $this->postValuation($this->totalBody('2026-03-20', '5.00'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);

        $this->send('POST', '/api/v1/portfolio-valuations/'.self::string($valuation['id']).'/withdraw', ['version' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        self::assertSame(1, $this->rows('portfolio_valuations', 'superseded_at IS NULL'));
    }

    public function testTheSameIdempotencyKeyReplaysTheFirstResponseWithoutASecondWrite(): void
    {
        $body = $this->totalBody('2026-03-31', '10.00');
        $this->postValuation($body, ['HTTP_IDEMPOTENCY_KEY' => 'valuation-key-0000001']);
        self::assertResponseStatusCodeSame(201);
        $first = $this->decode();

        $this->postValuation($body, ['HTTP_IDEMPOTENCY_KEY' => 'valuation-key-0000001']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertSame($first['id'], $this->decode()['id']);
        self::assertSame(1, $this->rows('portfolio_valuations'));

        $this->postValuation($this->totalBody('2026-03-31', '11.00'), ['HTTP_IDEMPOTENCY_KEY' => 'valuation-key-0000001']);
        self::assertResponseStatusCodeSame(409, 'the same key with another body is a conflict');
    }

    public function testTheFiveThousandthActiveValuationIsTheLast(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO portfolio_valuations (id, workspace_id, account_id, valued_on, asset_code, cash_value, cash_scale, derived_cash_value, derived_cash_scale, cash_source,
                securities_value_value, securities_value_scale, total_value_value, total_value_scale, detail, completeness, source, recorded_at, recorded_by, version)
             SELECT ('00000000-0000-7000-8002-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, DATE '2026-01-01' + n, 'EUR', 0, 0, 0, 0, 'DERIVED',
                0, 0, 0, 0, 'TOTAL', 'COMPLETE', 'MANUAL', now(), :owner, 1 FROM generate_series(1, 5000) AS n",
            ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::ACCOUNT, 'owner' => WorkspaceFixture::OWNER_ID],
        );

        $this->postValuation($this->totalBody('2026-01-01', '1.00'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('valuation_limit_reached', $this->decode()['code']);
        self::assertSame(5000, $this->rows('portfolio_valuations'));

        $this->postValuation($this->totalBody('2026-03-01', '1.00') + ['version' => 1]);
        self::assertResponseStatusCodeSame(201, 'a replacement keeps the count of active valuations');
        self::assertSame(5000, $this->rows('portfolio_valuations', 'superseded_at IS NULL'));
    }

    public function testNothingIsReadableOrWritableAcrossWorkspaces(): void
    {
        $own = $this->recordTotal('2026-03-31', '10.00');
        $this->connection->update('account_financial_accounts', ['workspace_id' => WorkspaceFixture::OTHER_WORKSPACE], ['id' => self::SNAPSHOTS_ACCOUNT]);
        $foreign = self::FOREIGN_ACCOUNT;

        $this->get('/api/v1/accounts/'.$foreign.'/portfolio-valuations');
        self::assertResponseStatusCodeSame(404);
        $this->postValuation($this->totalBody('2026-03-31', '10.00'), [], $foreign);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('DELETE', '/api/v1/session', server: ['HTTP_ACCEPT' => 'application/json']);
        $this->loginAsStranger();
        $this->get('/api/v1/portfolio-valuations/'.self::string($own['id']));
        self::assertResponseStatusCodeSame(404);
        $this->send('POST', '/api/v1/portfolio-valuations/'.self::string($own['id']).'/withdraw', ['version' => 1]);
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations');
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations/draft?valuedOn=2026-03-31');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->rows('portfolio_valuations', 'superseded_at IS NULL'));
    }

    public function testAnAccountThatIsNotInPortfolioModeAndMalformedIdentifiersAreNotFoundOrRefused(): void
    {
        $this->get('/api/v1/accounts/'.self::SNAPSHOTS_ACCOUNT.'/portfolio-valuations');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('account_not_portfolio', $this->decode()['code']);

        $this->get('/api/v1/accounts/not-a-uuid/portfolio-valuations');
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/portfolio-valuations/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/portfolio-valuations/00000000-0000-7000-8000-00000000ffff');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMutationsRequireTheCsrfToken(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'invalid');

        $this->postValuation($this->totalBody('2026-03-31', '10.00'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rows('portfolio_valuations'));
    }

    public function testWithdrawingRequiresTheCsrfToken(): void
    {
        $valuation = $this->recordTotal('2026-03-31', '10.00');
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'invalid');

        $this->send('POST', '/api/v1/portfolio-valuations/'.self::string($valuation['id']).'/withdraw', ['version' => $valuation['version']]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rows('portfolio_valuations', 'withdrawn'));
        self::assertSame(0, $this->rows('audit_events', "event_type = 'portfolio_valuation.withdrawn'"));
    }

    public function testAnonymousCallersAreRefused(): void
    {
        $this->client->request('DELETE', '/api/v1/session', server: ['HTTP_ACCEPT' => 'application/json']);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations');

        self::assertResponseStatusCodeSame(401);
    }

    private function seedHoldings(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('3662.18')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-03', 'instrumentId' => self::ETF, 'quantity' => '10', 'unitPrice' => '231.20', 'grossAmount' => $this->eur('2312.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-03', 'instrumentId' => self::STOCK, 'quantity' => '3', 'unitPrice' => '52.30', 'grossAmount' => $this->eur('156.90')]);
    }

    /** @return list<array<string, mixed>> */
    private function currentPrices(string $date): array
    {
        return [
            ['instrumentId' => self::ETF, 'price' => '231.20', 'priceDate' => $date],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => $date],
        ];
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

    /** @return array<string, mixed> */
    private function draft(string $valuedOn): array
    {
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations/draft?valuedOn='.$valuedOn);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function show(string $id): array
    {
        $this->get('/api/v1/portfolio-valuations/'.$id);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function list(string $account, string $query = ''): array
    {
        $this->get('/api/v1/accounts/'.$account.'/portfolio-valuations'.$query);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function record(array $body): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-operations', $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    private function loginAsStranger(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->send('POST', '/api/v1/session', ['email' => WorkspaceFixture::OTHER_OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);
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

    /**
     * @param array<string, mixed> $holder
     *
     * @return list<array<string, mixed>>
     */
    private static function lines(array $holder): array
    {
        $lines = $holder['positions'] ?? null;
        self::assertIsList($lines);

        return array_map(self::map(...), $lines);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<mixed>
     */
    private static function items(array $page): array
    {
        self::assertIsList($page['items']);

        return $page['items'];
    }

    /** @param array<string, mixed> $holder */
    private static function amount(array $holder, string $field): string
    {
        $amount = self::map($holder[$field] ?? null);
        self::assertSame('EUR', $amount['assetCode']);

        return self::string($amount['value']);
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
