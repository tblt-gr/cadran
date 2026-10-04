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

final class PortfolioValuationHistoryControllerTest extends WebTestCase
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

    public function testTheSeriesKeepsTheObservedTotalsTheGapAndNeverAZero(): void
    {
        $this->seedHoldings();
        $this->recordTotal('2026-07-31', '3500.00');
        $this->recordPositions('2026-08-31', [
            ['instrumentId' => self::ETF, 'price' => null, 'priceDate' => null],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-08-31'],
        ]);
        $this->recordPositions('2026-09-30', [
            ['instrumentId' => self::ETF, 'price' => '231.4521', 'priceDate' => '2026-09-30'],
            ['instrumentId' => self::STOCK, 'price' => '52.30', 'priceDate' => '2026-09-30'],
        ]);

        $history = $this->history(self::ACCOUNT);

        self::assertSame(['from', 'to', 'assetCode', 'truncated', 'points'], array_keys($history));
        self::assertSame('EUR', $history['assetCode']);
        self::assertFalse($history['truncated']);
        $points = self::points($history);
        self::assertSame(['2026-07-31', '2026-08-31', '2026-09-30'], array_column($points, 'valuedOn'));
        self::assertSame(['valuationId', 'valuedOn', 'detail', 'totalValue', 'securitiesValue', 'cash', 'completeness', 'priceQuality', 'flags'], array_keys($points[0]));
        self::assertSame('3500.00', self::amount($points[0], 'totalValue'));
        self::assertNull($points[1]['totalValue']);
        self::assertNull($points[1]['securitiesValue']);
        self::assertSame('3664.701', self::amount($points[2], 'totalValue'));
        self::assertSame(['TOTAL', 'POSITIONS', 'POSITIONS'], array_column($points, 'detail'));
        self::assertSame(['COMPLETE', 'PARTIAL', 'COMPLETE'], array_column($points, 'completeness'));
        self::assertSame('MISSING', $points[1]['priceQuality']);
    }

    public function testASupersededOrWithdrawnValuationIsAbsentAndAReplacementKeepsOnePoint(): void
    {
        $first = $this->recordTotal('2026-01-31', '10.00');
        $this->recordTotal('2026-01-31', '11.00', ['version' => $first['version']]);
        $gone = $this->recordTotal('2026-02-28', '20.00');
        $this->send('POST', '/api/v1/portfolio-valuations/'.self::string($gone['id']).'/withdraw', ['version' => $gone['version']]);
        self::assertResponseStatusCodeSame(200);
        $this->recordTotal('2026-03-31', '30.00');

        $points = self::points($this->history(self::ACCOUNT));

        self::assertSame(['2026-01-31', '2026-03-31'], array_column($points, 'valuedOn'));
        self::assertSame('11.00', self::amount($points[0], 'totalValue'));
        self::assertNotSame($first['id'], $points[0]['valuationId']);
    }

    public function testTheRangeBoundsAreInclusiveAndAnOmittedFromMeansSinceTheFirstValuation(): void
    {
        foreach (['2026-01-31' => '10.00', '2026-02-28' => '20.00', '2026-03-31' => '30.00'] as $day => $total) {
            $this->recordTotal($day, $total);
        }

        self::assertSame(['2026-02-28', '2026-03-31'], array_column(self::points($this->history(self::ACCOUNT, '?from=2026-02-28&to=2026-03-31')), 'valuedOn'));
        self::assertSame(['2026-01-31', '2026-02-28'], array_column(self::points($this->history(self::ACCOUNT, '?to=2026-02-28')), 'valuedOn'));
        $all = $this->history(self::ACCOUNT);
        self::assertNull($all['from']);
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column(self::points($all), 'valuedOn'));
        $bounded = $this->history(self::ACCOUNT, '?from=2026-02-01&to=2026-02-28');
        self::assertSame('2026-02-01', $bounded['from']);
        self::assertSame('2026-02-28', $bounded['to']);
        self::assertSame(gmdate('Y-m-d'), $all['to'], 'to defaults to today');
        self::assertSame([], self::points($this->history(self::ACCOUNT, '?from=2026-04-01&to=2026-04-30')));
    }

    public function testAnInvalidRangeIsRefusedWithItsCode(): void
    {
        foreach (['?to=2099-01-01', '?from=2026-03-01&to=2026-02-01', '?from=bad', '?to=2026-02-30', '?from[]=2026-01-01'] as $query) {
            $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuation-history'.$query);
            self::assertResponseStatusCodeSame(422, $query);
            self::assertSame('invalid_history_range', $this->decode()['code'], $query);
        }
    }

    public function testAnEmptyAccountHasNoPoint(): void
    {
        $history = $this->history(self::ACCOUNT);

        self::assertSame([], $history['points']);
        self::assertFalse($history['truncated']);
        self::assertSame('EUR', $history['assetCode']);
    }

    public function testTwoHundredAndFortyOnePointsReturnTheMostRecentTwoHundredAndFortyAsTruncated(): void
    {
        $this->seedValuations(241);

        $history = $this->history(self::ACCOUNT);
        $points = self::points($history);

        self::assertTrue($history['truncated']);
        self::assertCount(240, $points);
        self::assertSame('2025-01-03', $points[0]['valuedOn'], 'the oldest point is dropped');
        self::assertSame('2025-08-30', $points[239]['valuedOn']);

        $exact = self::points($this->history(self::ACCOUNT, '?from=2025-01-03'));
        self::assertCount(240, $exact);
        self::assertFalse($this->history(self::ACCOUNT, '?from=2025-01-03')['truncated']);
    }

    public function testAnOperationDatedBeforeAValuationButRecordedLaterFlagsOnlyTheAffectedPoints(): void
    {
        $this->seedHoldings();
        $this->recordTotal('2026-03-02', '3662.18');
        $this->recordTotal('2026-03-31', '3664.70');
        $this->record(['type' => 'BUY', 'tradeDate' => '2026-03-20', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '240.00', 'grossAmount' => $this->eur('240.00')]);

        $points = self::points($this->history(self::ACCOUNT));

        self::assertSame(['2026-03-02', '2026-03-31'], array_column($points, 'valuedOn'));
        self::assertSame([], $points[0]['flags'], 'the operations of 2026-03-02 are the deposit only, as frozen');
        self::assertSame(['POSITIONS_CHANGED'], $points[1]['flags']);
        self::assertSame('3664.70', self::amount($points[1], 'totalValue'), 'nothing is rewritten');
    }

    public function testVeryLargeDecimalsTravelAsExactStrings(): void
    {
        $large = '99999999999999999999999999.99';
        $this->recordTotal('2026-03-31', $large);

        self::assertSame($large, self::amount(self::points($this->history(self::ACCOUNT))[0], 'totalValue'));
    }

    public function testAZeroTotalStaysZeroAndIsNotAGap(): void
    {
        $this->recordTotal('2026-03-31', '0.00');

        $point = self::points($this->history(self::ACCOUNT))[0];

        self::assertSame('0.00', self::amount($point, 'totalValue'));
    }

    public function testNothingIsReadableAcrossWorkspacesAndMalformedOrNonPortfolioAccountsAreRefused(): void
    {
        $this->recordTotal('2026-03-31', '10.00');

        $this->get('/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-valuation-history');
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/accounts/not-an-id/portfolio-valuation-history');
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/accounts/'.self::SNAPSHOTS_ACCOUNT.'/portfolio-valuation-history');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('account_not_portfolio', $this->decode()['code']);

        $this->client->request('DELETE', '/api/v1/session', server: ['HTTP_ACCEPT' => 'application/json']);
        $this->loginAsStranger();
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuation-history');
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-valuation-history');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::points($this->decode()), 'no point of another workspace');
    }

    public function testAnonymousCallersAreRefused(): void
    {
        $this->client->request('DELETE', '/api/v1/session', server: ['HTTP_ACCEPT' => 'application/json']);
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuation-history');

        self::assertResponseStatusCodeSame(401);
    }

    private function seedValuations(int $count): void
    {
        $this->connection->executeStatement(
            "INSERT INTO portfolio_valuations (id, workspace_id, account_id, valued_on, asset_code, cash_value, cash_scale, derived_cash_value, derived_cash_scale, cash_source,
                securities_value_value, securities_value_scale, total_value_value, total_value_scale, detail, completeness, source, recorded_at, recorded_by, version)
             SELECT ('00000000-0000-7000-8002-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, DATE '2025-01-01' + n, 'EUR', 0, 0, 0, 0, 'DERIVED',
                0, 0, n, 0, 'TOTAL', 'COMPLETE', 'MANUAL', now(), :owner, 1 FROM generate_series(1, :count) AS n",
            ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::ACCOUNT, 'owner' => WorkspaceFixture::OWNER_ID, 'count' => $count],
        );
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
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations', ['valuedOn' => $valuedOn, 'detail' => 'TOTAL', 'totalValue' => $this->eur($total)] + $extra);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /**
     * @param list<array<string, mixed>> $prices
     *
     * @return array<string, mixed>
     */
    private function recordPositions(string $valuedOn, array $prices): array
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-valuations', ['valuedOn' => $valuedOn, 'detail' => 'POSITIONS', 'prices' => $prices]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function record(array $body): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-operations', $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function history(string $account, string $query = ''): array
    {
        $this->get('/api/v1/accounts/'.$account.'/portfolio-valuation-history'.$query);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $history
     *
     * @return list<array<string, mixed>>
     */
    private static function points(array $history): array
    {
        self::assertIsList($history['points']);

        return array_map(self::map(...), $history['points']);
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
