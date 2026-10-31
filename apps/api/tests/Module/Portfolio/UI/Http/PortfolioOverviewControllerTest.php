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

final class PortfolioOverviewControllerTest extends WebTestCase
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

    public function testSummaryUsesTheSelectedValuationFrozenCostAndYearToDateCash(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-01', 'grossAmount' => $this->eur('3662.18')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-02', 'instrumentId' => self::ETF, 'quantity' => '10', 'unitPrice' => '235.00', 'grossAmount' => $this->eur('2350.00')]);
        $valuation = $this->recordPositions('2026-03-31', [['instrumentId' => self::ETF, 'price' => '247.1421', 'priceDate' => '2026-03-31']]);
        $summary = $this->overview('summary', '2026-03-31');
        self::assertSame('121.421', self::map($summary['unrealizedGain'])['value']);
        self::assertSame('2350.00', self::map($summary['unrealizedGain'])['costBasis']);
        self::assertSame('0.051669', self::map($summary['unrealizedGain'])['performance']);
        self::assertSame('3783.601', $summary['totalValue']);
        self::assertSame('1312.18', $summary['cash']);
        self::assertSame('3662.18', self::map($summary['netDeposits'])['value']);
        self::assertSame('RECONCILED', self::map($summary['reconciliation'])['status'], 'external flows alone do not require review');
        self::assertSame($valuation['id'], self::map($summary['valuation'])['id']);
        $this->get('/api/v1/portfolios');
        self::assertResponseIsSuccessful();
        $card = self::map(self::items($this->decode())[0]);
        self::assertSame('3783.601', self::map($card['latestValuation'])['totalValue']);
        self::assertSame('1312.18', $card['currentCash']);
        self::assertSame(1, $card['positionCount']);
        $explanations = self::map($summary['explanations']);
        self::assertCount(6, $explanations);
        $sources = self::map($explanations['TOTAL_VALUE'])['sources'];
        self::assertIsList($sources);
        self::assertSame('VALUATION', self::map($sources[0])['kind']);
        self::assertFalse(self::map($this->overview('summary', '2026-05-01')['valuation'])['stale']);
        self::assertTrue(self::map($this->overview('summary', '2026-05-02')['valuation'])['stale']);
        self::assertSame('STALE_VALUATION', self::map(self::map($this->overview('summary', '2026-05-02')['explanations'])['TOTAL_VALUE'])['reason']);
        $this->recordTotal('2026-06-01', '4000.00');
        self::assertSame('337.82', self::map($this->overview('summary', '2026-06-01')['unrealizedGain'])['value'], 'TOTAL uses frozen costs');
        self::assertSame($valuation['id'], self::map($this->overview('summary', '2026-03-31')['valuation'])['id']);
    }

    public function testAllocationIsExactIncludesCashAndAnUnspecifiedBucket(): void
    {
        $this->seedHoldings();
        $this->recordPositions('2026-03-31', [
            ['instrumentId' => self::ETF, 'price' => '231.4521', 'priceDate' => '2026-03-31'],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-31'],
        ]);
        $allocation = $this->overview('allocation', '2026-03-31', '&by=ASSET_CLASS');
        $items = self::items($allocation);
        self::assertCount(2, $items);
        self::assertSame('UNSPECIFIED', self::map($items[0])['key']);
        self::assertSame('2471.421', self::map($items[0])['value']);
        self::assertSame('Non renseignée', self::map($items[0])['label']);
        self::assertSame('CASH', self::map($items[1])['key']);
        self::assertNull($allocation['reason']);
        $sum = \App\Module\Foundation\Domain\ExactDecimal::addForResponse(self::string(self::map($items[0])['share']), self::string(self::map($items[1])['share']));
        self::assertSame(0, \App\Module\Foundation\Domain\ExactDecimal::compareForResponse($sum, '1'));
        self::assertCount(3, self::items($this->overview('allocation', '2026-03-31')));
        self::assertSame('MARKET', $allocation['basis']);
        $this->recordTotal('2026-04-30', '4000.00');
        $total = $this->overview('allocation', '2026-04-30', '&by=ASSET_CLASS');
        self::assertSame('NOT_DETAILED', $total['reason']);
        self::assertSame('COST', $total['basis'], 'a total-only statement is split at the exact cost of its lines');
        self::assertSame('3662.18', $total['total'], 'costs 2312.00 + 156.90 plus cash 1193.28');
        $items = self::items($total);
        self::assertSame(['UNSPECIFIED', 'CASH'], array_map(static fn ($item): mixed => self::map($item)['key'], $items));
        self::assertSame('2468.90', self::map($items[0])['value']);
        self::assertSame('1193.28', self::map($items[1])['value']);
        self::assertIsList($total['slices']);
        self::assertCount(2, $total['slices']);
    }

    public function testATotalValuationWithAnUnknownCostHasNoSplit(): void
    {
        $this->record(['type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-03-01', 'instrumentId' => self::ETF, 'quantity' => '1']);
        $this->recordTotal('2026-03-31', '250.00');
        $total = $this->overview('allocation', '2026-03-31');
        self::assertSame('NOT_DETAILED', $total['reason']);
        self::assertNull($total['basis']);
        self::assertSame([], $total['slices']);
        self::assertNull(self::map(self::items($total)[0])['share']);
    }

    public function testPartialUnknownCostAndMissingValuationsStayNull(): void
    {
        self::assertSame('NO_VALUATION', self::map($this->overview('summary', '2026-01-01')['unrealizedGain'])['reason']);
        self::assertSame('NO_VALUATION', $this->overview('allocation', '2026-01-01')['reason']);
        $this->seedHoldings();
        $this->recordPositions('2026-03-31', [
            ['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-31'],
        ]);
        self::assertSame('PARTIAL_VALUATION', self::map($this->overview('summary', '2026-03-31')['unrealizedGain'])['reason']);
        self::assertNull($this->overview('summary', '2026-03-31')['totalValue']);
        self::assertSame('PARTIAL_VALUATION', $this->overview('allocation', '2026-03-31')['reason']);
        self::assertNull($this->overview('summary', '2026-03-31')['newerPartialValuation']);
        self::assertNull($this->overview('allocation', '2026-03-31')['newerPartialValuation']);
        $this->record(['type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-04-01', 'instrumentId' => self::ETF, 'quantity' => '1']);
        $this->recordTotal('2026-04-30', '4000.00');
        self::assertSame('UNKNOWN_LOT_COST', self::map($this->overview('summary', '2026-04-30')['unrealizedGain'])['reason']);
        $partial = $this->recordPositions('2026-05-31', [
            ['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-05-31'],
        ]);
        $summary = $this->overview('summary', '2026-05-31');
        self::assertSame('UNKNOWN_LOT_COST', self::map($summary['unrealizedGain'])['reason']);
        self::assertNull(self::map($summary['unrealizedGain'])['value']);
        self::assertSame($partial['id'], self::map($summary['newerPartialValuation'])['id']);
    }

    public function testReconciliationIncludesPriorYearTransferWarningsButExcludesPresentationWarnings(): void
    {
        $this->connection->update('account_financial_accounts', ['opened_on' => '2025-01-01'], ['id' => self::ACCOUNT]);
        $this->portfolio->transferLeg('00000000-0000-7000-8000-000000000099', self::ACCOUNT, '100.00', '2025-12-01');
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-01', 'grossAmount' => $this->eur('100.00')]);
        $reconciliation = self::map($this->overview('summary', '2026-03-31')['reconciliation']);
        self::assertSame('NEEDS_REVIEW', $reconciliation['status']);
        self::assertSame([['code' => 'UNLINKED_TRANSFER_IN', 'count' => 1]], $reconciliation['warnings']);
    }

    public function testTheListFiltersWorkspaceModeAndArchivesAndCapsAfterSorting(): void
    {
        $this->portfolio->account('00000000-0000-7000-8000-0000000000d9', label: 'Archived', archived: true);
        $this->get('/api/v1/portfolios');
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::items($this->decode()));
        self::assertSame(self::ACCOUNT, self::map(self::items($this->decode())[0])['accountId']);
        for ($i = 1; $i <= 51; ++$i) {
            $this->portfolio->account(sprintf('00000000-0000-7000-8003-%012d', $i), label: sprintf('A %03d', $i));
        }
        $this->get('/api/v1/portfolios');
        self::assertResponseIsSuccessful();
        $list = $this->decode();
        self::assertCount(50, self::items($list));
        self::assertTrue($list['truncated']);
        self::assertSame('A 001', self::map(self::items($list)[0])['label']);
        self::assertSame('A 050', self::map(self::items($list)[49])['label']);
    }

    public function testUnknownAndForeignAccountsAreNotFoundAndInvalidDatesAreRejected(): void
    {
        foreach (['summary', 'allocation'] as $endpoint) {
            foreach ([self::FOREIGN_ACCOUNT, 'not-a-uuid', '00000000-0000-7000-8000-00000000ffff'] as $account) {
                $this->get('/api/v1/accounts/'.$account.'/portfolio-'.$endpoint.'?asOf=2026-03-31');
                self::assertResponseStatusCodeSame(404);
                self::assertSame('Compte introuvable', $this->decode()['title']);
                self::assertSame('Aucun compte ne porte cet identifiant dans cet espace de travail.', $this->decode()['detail']);
            }
            foreach (['2026-02-30', '2099-01-01', 'bad', '2026-03-31&asOf[]=2026-03-31'] as $date) {
                $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-'.$endpoint.'?asOf='.$date);
                self::assertResponseStatusCodeSame(422);
                self::assertSame('invalid_as_of', $this->decode()['code']);
                self::assertSame('Date de portefeuille invalide', $this->decode()['title']);
                self::assertSame("Choisissez une date réelle au plus tard aujourd'hui.", $this->decode()['detail']);
            }
            $empty = $this->overview($endpoint, '2025-12-31');
            self::assertNull($empty['valuation']);
        }
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-allocation?by=bad');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_allocation_by', $this->decode()['code']);
        self::assertSame('Répartition invalide', $this->decode()['title']);
        self::assertSame('Choisissez un regroupement disponible.', $this->decode()['detail']);
        $this->client->request('DELETE', '/api/v1/session', server: ['HTTP_ACCEPT' => 'application/json']);
        $this->get('/api/v1/portfolios');
        self::assertResponseStatusCodeSame(401);
    }

    public function testSourcePreviewsAreBoundedAndRoundingResidualsNeverRequireReview(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-01', 'grossAmount' => $this->eur('3000.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-02', 'instrumentId' => self::ETF, 'quantity' => '10', 'unitPrice' => '230.5688', 'grossAmount' => $this->eur('2305.69')]);
        self::assertSame([], self::map($this->overview('summary', '2026-03-31')['reconciliation'])['warnings']);
        $this->connection->executeStatement(
            "INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code, gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale, state, created_at, created_by, version)
             SELECT ('00000000-0000-7000-8004-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, 'DEPOSIT', '2026-03-15', n, 'EUR', 1, 0, 0, 0, 0, 0, 1, 0, 'ACTIVE', now(), :owner, 1 FROM generate_series(1, 55) AS n",
            ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::ACCOUNT, 'owner' => WorkspaceFixture::OWNER_ID],
        );
        $summary = $this->overview('summary', '2026-03-31');
        $cash = self::map(self::map($summary['explanations'])['CASH']);
        self::assertIsArray($cash['sources']);
        self::assertCount(50, $cash['sources']);
        self::assertSame(7, $cash['truncatedSourceCount']);
        self::assertSame('RECONCILED', self::map($summary['reconciliation'])['status']);
    }

    public function testReleaseValuationSnapshotsCashAndPublishedTotalsReconcileExactly(): void
    {
        $this->seedHoldings();
        foreach (['2026-03-31', '2026-04-30'] as $day) {
            $valuation = $this->recordPositions($day, [
                ['instrumentId' => self::ETF, 'price' => '231.4521', 'priceDate' => $day],
                ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => $day],
            ]);
            $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-positions?asOf='.$day);
            self::assertResponseIsSuccessful();
            $positions = self::items($this->decode());
            foreach ($positions as $position) {
                $position = self::map($position);
                $instrument = self::map($position['instrument']);
                $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-positions/'.self::string($instrument['id']).'/lots?asOf='.$day);
                self::assertResponseIsSuccessful();
                $lots = $this->decode()['openLots'];
                self::assertIsList($lots);
                $quantity = '0';
                foreach ($lots as $lot) {
                    $quantity = \App\Module\Foundation\Domain\ExactDecimal::addForResponse($quantity, self::string(self::map($lot)['openQuantity']));
                }
                self::assertSame(0, \App\Module\Foundation\Domain\ExactDecimal::compareForResponse($quantity, self::string($position['quantity'])));
            }
            $summary = $this->overview('summary', $day);
            self::assertSame(self::string(self::map($valuation['derivedCash'])['value']), $summary['cash']);
            $marketSum = '0';
            $lines = $valuation['positions'];
            self::assertIsList($lines);
            foreach ($lines as $line) {
                $marketSum = \App\Module\Foundation\Domain\ExactDecimal::addForResponse($marketSum, self::string(self::map(self::map($line)['marketValue'])['value']));
            }
            $total = \App\Module\Foundation\Domain\ExactDecimal::addForResponse(self::string($summary['cash']), $marketSum);
            self::assertSame($total, $summary['totalValue']);
            self::assertSame($total, $this->connection->fetchOne("SELECT amount_literal FROM account_balance_snapshots WHERE account_id = ? AND source = 'CALCULATED' AND superseded_at IS NULL ORDER BY as_of DESC LIMIT 1", [self::ACCOUNT]));
        }
    }

    public function testSummaryCashIsTheStatementCashOfTheRetainedValuation(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-01', 'grossAmount' => $this->eur('3000.00')]);
        $this->recordTotal('2026-03-31', '3150.00', ['observedCash' => $this->eur('120.50')]);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-04-10', 'grossAmount' => $this->eur('200.00')]);

        $summary = $this->overview('summary', '2026-04-15');
        self::assertSame('3150.00', $summary['totalValue']);
        self::assertSame('120.50', $summary['cash'], 'the statement cash, not the ledger at asOf');
        $explanation = self::map(self::map($summary['explanations'])['CASH']);
        self::assertSame('OBSERVED', $explanation['quality']);
        self::assertSame('2026-03-31', self::map($explanation['period'])['to']);

        $this->recordTotal('2026-04-30', '3400.00');
        $derived = $this->overview('summary', '2026-04-30');
        self::assertSame('3200.00', $derived['cash'], 'without statement cash, the ledger on the valuation date');
        self::assertSame('DERIVED', self::map(self::map($derived['explanations'])['CASH'])['quality']);
    }

    public function testLargeSignedAllocationIsAnExactSuccessfulRead(): void
    {
        $this->record(['type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-03-01', 'instrumentId' => self::ETF, 'quantity' => '1', 'costBasis' => $this->eur('0')]);
        $this->recordPositions('2026-03-31', [['instrumentId' => self::ETF, 'price' => '10000000000000000000000000', 'priceDate' => '2026-03-31']],
            ['observedCash' => $this->eur('-9999999999999999999999999.99')]);
        $allocation = $this->overview('allocation', '2026-03-31');
        self::assertSame('0.01', $allocation['total']);
        self::assertNull($allocation['reason']);
        $items = self::items($allocation);
        self::assertSame('10000000000000000000000000', self::map($items[0])['value']);
        self::assertSame('1000000000000000000000000000.000000000000000000000000', self::map($items[0])['share']);
        self::assertSame('-9999999999999999999999999.99', self::map($items[1])['value']);
        self::assertSame('-999999999999999999999999999.000000000000000000000000', self::map($items[1])['share']);
    }

    public function testACompleteValuationRemainsVisibleWhenANewerObservationIsPartial(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-09-01', 'grossAmount' => $this->eur('3000.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-09-02', 'instrumentId' => self::ETF, 'quantity' => '10', 'unitPrice' => '235.00', 'grossAmount' => $this->eur('2350.00')]);
        $complete = $this->recordPositions('2026-09-30', [['instrumentId' => self::ETF, 'price' => '247.1421', 'priceDate' => '2026-09-30']]);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-10-01', 'grossAmount' => $this->eur('25.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-10-01', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '240.00', 'grossAmount' => $this->eur('240.00')]);
        $partial = $this->recordPositions('2026-10-03', [['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null]], ['observedCash' => $this->eur('445.00')]);
        $summary = $this->overview('summary', '2026-10-03');
        self::assertSame('3121.421', $summary['totalValue']);
        self::assertSame('121.421', self::map($summary['unrealizedGain'])['value']);
        self::assertSame('650.00', $summary['cash'], 'cash shares the date of the retained complete valuation');
        self::assertSame($complete['id'], self::map($summary['valuation'])['id']);
        self::assertSame(3, self::map($summary['valuation'])['ageDays']);
        self::assertSame($partial['id'], self::map($summary['newerPartialValuation'])['id']);
        $codes = self::map($summary['reconciliation'])['warnings'];
        self::assertIsList($codes);
        self::assertContains('PARTIAL_VALUATION', array_column($codes, 'code'));
        self::assertContains('CASH_DIFFERENCE', array_column($codes, 'code'));
        $explanations = self::map($summary['explanations']);
        $totalSources = self::map($explanations['TOTAL_VALUE'])['sources'];
        self::assertIsList($totalSources);
        self::assertSame($complete['id'], self::map($totalSources[0])['id']);
        $reconciliationSources = self::map($explanations['RECONCILIATION'])['sources'];
        self::assertIsList($reconciliationSources);
        self::assertContains($complete['id'], array_column($reconciliationSources, 'id'));
        self::assertContains($partial['id'], array_column($reconciliationSources, 'id'));
        $allocation = $this->overview('allocation', '2026-10-03');
        self::assertNull($allocation['reason']);
        self::assertSame($complete['id'], self::map($allocation['valuation'])['id']);
        self::assertSame($partial['id'], self::map($allocation['newerPartialValuation'])['id']);
        self::assertSame('2471.421', self::map(self::items($allocation)[0])['value']);
        self::assertNull($this->overview('summary', '2026-10-02')['newerPartialValuation']);
        self::assertNull($this->overview('summary', '2026-09-29')['valuation']);
        $this->get('/api/v1/portfolios');
        self::assertResponseIsSuccessful();
        $card = self::map(self::items($this->decode())[0]);
        self::assertSame($complete['id'], self::map($card['latestValuation'])['id']);
        self::assertSame($partial['id'], self::map($card['newerPartialValuation'])['id']);
        $newComplete = $this->recordTotal('2026-10-03', '4000.00', ['version' => $partial['version']]);
        $summary = $this->overview('summary', '2026-10-03');
        self::assertSame($newComplete['id'], self::map($summary['valuation'])['id']);
        self::assertNull($summary['newerPartialValuation']);
        self::assertSame('4000.00', $summary['totalValue']);
        $this->send('POST', '/api/v1/portfolio-valuations/'.self::string($newComplete['id']).'/withdraw', ['version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame($complete['id'], self::map($this->overview('summary', '2026-10-03')['valuation'])['id']);
        self::assertNull($this->overview('summary', '2026-10-03')['newerPartialValuation'], 'superseded partial and withdrawn complete stay excluded');
    }

    public function testFallbackAgeUsesTheCompleteDateAndNeverUsesFutureOrPreOpeningObservations(): void
    {
        $this->seedHoldings();
        $complete = $this->recordPositions('2026-08-31', [
            ['instrumentId' => self::ETF, 'price' => '231.4521', 'priceDate' => '2026-08-31'],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-08-31'],
        ]);
        $partial = $this->recordPositions('2026-10-03', [
            ['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-08-31'],
        ]);
        $future = $this->recordTotal('2026-10-02', '9999.00');
        $this->connection->update('portfolio_valuations', ['valued_on' => '2026-10-04'], ['id' => $future['id']]);
        $summary = $this->overview('summary', '2026-10-03');
        self::assertSame($complete['id'], self::map($summary['valuation'])['id']);
        self::assertSame(33, self::map($summary['valuation'])['ageDays']);
        self::assertTrue(self::map($summary['valuation'])['stale']);
        $warnings = self::map($summary['reconciliation'])['warnings'];
        self::assertIsList($warnings);
        self::assertContains('STALE_VALUATION', array_column($warnings, 'code'));
        self::assertContains('STALE_PRICES', array_column($warnings, 'code'), 'latest partial price flags remain visible');
        self::assertContains('PARTIAL_VALUATION', array_column($warnings, 'code'));
        $this->connection->update('portfolio_valuations', ['valued_on' => '2025-12-31'], ['id' => $complete['id']]);
        $summary = $this->overview('summary', '2026-10-03');
        self::assertSame($partial['id'], self::map($summary['valuation'])['id'], 'pre-opening complete is not a fallback');
        self::assertNull($summary['totalValue']);
        self::assertNull($summary['newerPartialValuation']);
        self::assertSame('PARTIAL_VALUATION', self::map($summary['unrealizedGain'])['reason']);
        self::assertSame('PARTIAL_VALUATION', $this->overview('allocation', '2026-10-03')['reason']);
    }

    public function testZeroDetailedValuationHasNoAllocationShares(): void
    {
        $this->recordPositions('2026-01-31', []);
        $zero = $this->overview('allocation', '2026-01-31');
        self::assertSame('NON_POSITIVE_TOTAL', $zero['reason']);
        self::assertSame([], $zero['slices']);
        self::assertNull(self::map(self::items($zero)[0])['share']);
    }

    public function testHistoryBeyondTheWriteBoundIsRejectedBeforeAnyReplay(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code, gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale, state, created_at, created_by, version)
             SELECT ('00000000-0000-7000-8005-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, 'DEPOSIT', '2026-03-15', n, 'EUR', 1, 0, 0, 0, 0, 0, 1, 0, 'ACTIVE', now(), :owner, 1 FROM generate_series(1, 10001) AS n",
            ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::ACCOUNT, 'owner' => WorkspaceFixture::OWNER_ID],
        );
        foreach (['/api/v1/portfolios', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-summary', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-allocation'] as $path) {
            $this->get($path);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('cash_range_too_dense', $this->decode()['code']);
        }
    }

    public function testTheListCarriesEachGainAndAWorkspaceScopedTotal(): void
    {
        $this->get('/api/v1/portfolios');
        $empty = self::map($this->decode()['total']);
        self::assertSame('NO_VALUATION', $empty['reason']);
        self::assertSame(0, $empty['valuedAccounts']);
        self::assertSame(1, $empty['unvaluedAccounts']);
        self::assertNull($empty['totalValue']);
        self::assertNull(self::map(self::map(self::items($this->decode())[0])['unrealizedGain'])['performance']);

        $this->seedHoldings();
        $this->recordPositions('2026-03-31', [
            ['instrumentId' => self::ETF, 'price' => '240.00', 'priceDate' => '2026-03-31'],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-31'],
        ]);
        $this->postValuation($this->totalBody('2026-03-31', '1000.00'), account: self::FOREIGN_ACCOUNT);
        $this->get('/api/v1/portfolios');
        $list = $this->decode();
        $gain = self::map(self::map(self::items($list)[0])['unrealizedGain']);
        self::assertNotNull($gain['value']);
        $total = self::map($list['total']);
        self::assertSame('EUR', $total['assetCode']);
        self::assertSame(1, $total['valuedAccounts']);
        self::assertSame(0, $total['unvaluedAccounts']);
        self::assertSame(self::map(self::map(self::items($list)[0])['latestValuation'])['totalValue'], $total['totalValue']);
        self::assertSame($gain['value'], $total['unrealizedGain']);
        self::assertSame($gain['costBasis'], $total['costBasis']);
        self::assertSame($gain['performance'], $total['performance']);
        self::assertFalse($list['truncated']);
    }

    public function testTwoValuedAccountsSumAndExposeTheOldestDate(): void
    {
        $second = '00000000-0000-7000-8000-0000000000e1';
        $this->portfolio->account($second, label: 'PEA');
        $this->seedHoldings();
        $this->recordPositions('2026-03-31', [
            ['instrumentId' => self::ETF, 'price' => '240.00', 'priceDate' => '2026-03-31'],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-31'],
        ]);
        $this->postValuation($this->totalBody('2026-02-28', '1000.00'), account: $second);
        self::assertResponseStatusCodeSame(201);
        $this->get('/api/v1/portfolios');
        $list = $this->decode();
        $items = self::items($list);
        self::assertCount(2, $items);
        $first = self::map(self::map($items[0])['latestValuation']);
        $other = self::map(self::map($items[1])['latestValuation']);
        $total = self::map($list['total']);
        self::assertSame(2, $total['valuedAccounts']);
        self::assertSame(0, $total['unvaluedAccounts']);
        self::assertSame(\Brick\Math\BigDecimal::of(self::string($first['totalValue']))->plus(self::string($other['totalValue']))->toScale(2)->__toString(), $total['totalValue']);
        self::assertSame('2026-02-28', $total['oldestValuedOn']);
        self::assertSame(0, $total['partialNewerAccounts']);
        self::assertNull($total['reason']);
        self::assertNotNull($total['unrealizedGain']);
        self::assertNotNull($total['costBasis']);
    }

    public function testMixedAssetsNullTheWholeTotal(): void
    {
        $this->portfolio->account('00000000-0000-7000-8000-0000000000e2', label: 'Crypto', assetCode: 'BTC');
        $this->postValuation($this->totalBody('2026-03-31', '1000.00'));
        self::assertResponseStatusCodeSame(201);
        $this->get('/api/v1/portfolios');
        $total = self::map($this->decode()['total']);
        self::assertSame('MIXED_ASSETS', $total['reason']);
        self::assertNull($total['assetCode']);
        self::assertNull($total['totalValue']);
        self::assertNull($total['oldestValuedOn']);
        self::assertSame(1, $total['valuedAccounts']);
    }

    public function testAPartialOnlyAccountIsUnvaluedAndANewerPartialIsCounted(): void
    {
        $this->seedHoldings();
        $this->recordPositions('2026-03-31', [
            ['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-03-31'],
        ]);
        $this->get('/api/v1/portfolios');
        $total = self::map($this->decode()['total']);
        self::assertSame('NO_VALUATION', $total['reason']);
        self::assertSame(0, $total['valuedAccounts']);
        self::assertSame(1, $total['unvaluedAccounts']);
        self::assertNull($total['oldestValuedOn']);

        $this->recordTotal('2026-04-30', '4000.00');
        $this->recordPositions('2026-05-31', [
            ['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-05-31'],
        ]);
        $this->get('/api/v1/portfolios');
        $total = self::map($this->decode()['total']);
        self::assertSame('4000.00', $total['totalValue']);
        self::assertSame('2026-04-30', $total['oldestValuedOn']);
        self::assertSame(1, $total['partialNewerAccounts']);
    }

    public function testAnotherWorkspaceValuedAccountIsAbsentFromItemsAndTotal(): void
    {
        $this->postValuation($this->totalBody('2026-03-31', '9999.00'), account: self::FOREIGN_ACCOUNT);
        self::assertResponseStatusCodeSame(404, 'the caller cannot value a foreign account');
        $foreign = '00000000-0000-7000-8000-0000000000e3';
        $this->portfolio->account($foreign, label: 'Voisin 2');
        $this->postValuation($this->totalBody('2026-03-31', '9999.00'), account: $foreign);
        self::assertResponseStatusCodeSame(201);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET session_replication_role = replica');
        $this->connection->update('portfolio_valuations', ['workspace_id' => WorkspaceFixture::OTHER_WORKSPACE], ['account_id' => $foreign]);
        $this->connection->update('account_financial_accounts', ['workspace_id' => WorkspaceFixture::OTHER_WORKSPACE], ['id' => $foreign]);
        $this->connection->executeStatement('SET session_replication_role = DEFAULT');
        $this->connection->commit();
        $this->get('/api/v1/portfolios');
        $list = $this->decode();
        self::assertSame([self::ACCOUNT], array_column(self::items($list), 'accountId'));
        $total = self::map($list['total']);
        self::assertSame(0, $total['valuedAccounts']);
        self::assertNull($total['totalValue']);
        self::assertStringNotContainsString('9999', (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function overview(string $endpoint, string $asOf, string $extra = ''): array
    {
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-'.$endpoint.'?asOf='.$asOf.$extra);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    private function seedHoldings(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('3662.18')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-03', 'instrumentId' => self::ETF, 'quantity' => '10', 'unitPrice' => '231.20', 'grossAmount' => $this->eur('2312.00')]);
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-03', 'instrumentId' => self::STOCK, 'quantity' => '3', 'unitPrice' => '52.30', 'grossAmount' => $this->eur('156.90')]);
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

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
