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

final class HoldingsControllerTest extends WebTestCase
{
    private const string PEA = PortfolioFixture::ACCOUNT;
    private const string CTO = '00000000-0000-7000-8000-0000000000e1';
    private const string AV = '00000000-0000-7000-8000-0000000000e2';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000d7';
    private const string FOREIGN_INSTRUMENT = '00000000-0000-7000-8000-000000000b09';
    private const string W = PortfolioFixture::ETF;
    private const string A = PortfolioFixture::STOCK;

    private KernelBrowser $client;
    private WorkspaceFixture $fixture;
    private Connection $connection;
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
        $this->portfolio->account(self::PEA, label: 'PEA');
        $this->portfolio->account(self::CTO, label: 'CTO');
        $this->portfolio->account(self::AV, label: 'Assurance-vie');
        $this->portfolio->account(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->portfolio->instrument(self::W, name: 'ETF Monde', isin: 'IE00B4L5Y983');
        $this->portfolio->instrument(self::A, name: 'Action Alpha', isin: 'FR0000120073');
        $this->portfolio->instrument(self::FOREIGN_INSTRUMENT, WorkspaceFixture::OTHER_WORKSPACE, 'Titre voisin', 'US0378331005');
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testAnInstrumentHeldOnTwoAccountsIsOneLineWithItsBreakdown(): void
    {
        $this->login();
        $this->holdETF(self::PEA, '10', '90.00', '2026-10-31', '100.00');
        $this->holdETF(self::CTO, '5', '96.00', '2026-11-03', '102.00');
        $this->postValuation(self::AV, ['valuedOn' => '2026-11-01', 'detail' => 'TOTAL', 'totalValue' => ['value' => '700.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);

        $holdings = $this->holdings();

        $items = self::list($holdings['items']);
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame(self::W, self::map($item['instrument'])['id']);
        self::assertSame('ETF', $item['instrumentType']);
        self::assertSame('1510.00', $item['value']);
        self::assertSame('1380.00', $item['costBasis']);
        self::assertSame('130.00', $item['unrealizedGain']);
        self::assertSame('0.094203', $item['performance']);
        self::assertSame('92.0000', $item['averageUnitCost']);
        // 1510 of 2210: the 700 of the total-valued account weighs in the denominator.
        self::assertSame('68.33', self::map($item['share'])['percentDisplay']);
        self::assertSame(['PEA', 'CTO'], array_column(self::list($item['accounts']), 'label'));
        self::assertSame(['code' => 'MIXED_VALUATION_DATES'], ['code' => self::map(self::list($holdings['warnings'])[0])['code']]);
        self::assertSame('2026-10-31', $holdings['valuedFrom']);
        self::assertSame('2026-11-03', $holdings['valuedTo']);
        self::assertSame('DEGRADED', $holdings['quality']);
        self::assertSame([['accountId' => self::AV, 'label' => 'Assurance-vie', 'reason' => 'TOTAL_VALUATION', 'securitiesValue' => '700.00']], $holdings['unsplitAccounts']);
        $accounts = self::list($holdings['accounts']);
        self::assertSame(['PEA', 'Assurance-vie', 'CTO'], array_column($accounts, 'label'));
        self::assertSame(['LINES', 'TOTAL', 'LINES'], array_column($accounts, 'detail'));
        self::assertSame(['1000.00', '700.00', '510.00'], array_column($accounts, 'securitiesValue'));
        self::assertSame('100.00', $accounts[0]['unrealizedGain']);
        self::assertSame([], $accounts[1]['lines']);
        self::assertSame('1000.00', self::list($accounts[0]['lines'])[0]['value']);
        self::assertFalse($holdings['truncated']);
        $totals = self::map($holdings['totals']);
        self::assertSame('2210.00', $totals['securitiesValue']);
        // The total-valued Assurance-vie has no frozen position: its cost is unknown, never a zero cost.
        self::assertNull($totals['costBasis']);
        self::assertNull($totals['unrealizedGain']);
        self::assertSame('UNKNOWN_LOT_COST', $totals['reason']);
        // The two costed lines are shown apart (1510 of value, 1380 of cost); the bare total account is the one exclusion.
        self::assertSame(['securitiesValue' => '1510.00', 'costBasis' => '1380.00', 'unrealizedGain' => '130.00', 'performance' => '0.094203', 'excludedCount' => 1, 'excludedValue' => '700.00'], $totals['knownCost']);
        self::assertNull($accounts[1]['costBasis']);
        self::assertNull($accounts[1]['unrealizedGain']);
        $slices = self::list(self::map($holdings['allocation'])['byAccount']);
        self::assertSame(['PEA', 'Assurance-vie', 'CTO'], array_column($slices, 'label'));
        self::assertNotNull($totals['totalValue']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAnotherWorkspaceNeverAppearsAndAnEmptyWorkspaceIsNotCalculable(): void
    {
        $this->login();
        $holdings = $this->holdings();
        self::assertSame([], $holdings['items']);
        $labels = array_column(self::list($holdings['unsplitAccounts']), 'label');
        sort($labels);
        self::assertSame(['Assurance-vie', 'CTO', 'PEA'], $labels);
        self::assertNotContains('Voisin', $labels);
        $accounts = self::list($holdings['accounts']);
        self::assertSame(['Assurance-vie', 'CTO', 'PEA'], array_column($accounts, 'label'));
        self::assertSame(['NONE', 'NONE', 'NONE'], array_column($accounts, 'detail'));
        self::assertSame('NO_VALUATION', self::map($holdings['totals'])['reason']);
        self::assertNull(self::map($holdings['totals'])['totalValue']);
        self::assertStringNotContainsString('Titre voisin', (string) $this->client->getResponse()->getContent());
    }

    public function testMoreThanFiftyAccountsAreTruncatedWithAWarning(): void
    {
        $this->login();
        for ($i = 1; $i <= 50; ++$i) {
            $this->portfolio->account(sprintf('00000000-0000-7000-8003-%012d', $i), label: sprintf('A %03d', $i));
        }
        $holdings = $this->holdings();
        self::assertTrue($holdings['truncated']);
        self::assertCount(50, self::list($holdings['unsplitAccounts']));
        self::assertCount(50, self::list($holdings['accounts']));
        self::assertSame([], self::list($holdings['warnings'])[0]['accountIds']);
        self::assertSame('TRUNCATED_ACCOUNTS', self::list($holdings['warnings'])[0]['code']);
    }

    public function testAnAccountBeyondItsReadBoundIsListedUnsplitInsteadOfFailingThePage(): void
    {
        $this->login();
        $this->holdETF(self::PEA, '10', '90.00', '2026-10-31', '100.00');
        $this->connection->executeStatement(
            "INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code, gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale, state, created_at, created_by, version)
             SELECT ('00000000-0000-7000-8005-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, 'DEPOSIT', '2026-03-15', n, 'EUR', 1, 0, 0, 0, 0, 0, 1, 0, 'ACTIVE', now(), :owner, 1 FROM generate_series(1, 10001) AS n",
            ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::CTO, 'owner' => WorkspaceFixture::OWNER_ID],
        );

        $holdings = $this->holdings();

        self::assertCount(1, self::list($holdings['items']));
        $dense = array_values(array_filter(self::list($holdings['unsplitAccounts']), static fn (array $account): bool => self::CTO === $account['accountId']));
        self::assertSame([['accountId' => self::CTO, 'label' => 'CTO', 'reason' => 'NO_VALUATION', 'securitiesValue' => null]], $dense);
        self::assertNull(self::map($holdings['totals'])['totalValue']);
    }

    public function testAnotherWorkspaceAccountIsAbsentFromThePerAccountView(): void
    {
        $this->login();
        $this->holdETF(self::PEA, '10', '90.00', '2026-10-31', '100.00');
        $ids = array_column(self::list($this->holdings()['accounts']), 'accountId');
        self::assertContains(self::PEA, $ids);
        self::assertNotContains(self::FOREIGN, $ids);
        self::assertStringNotContainsString('Voisin', (string) $this->client->getResponse()->getContent());
    }

    public function testEverySortIsAcceptedAndAnInvalidOneIsABadRequest(): void
    {
        $this->login();
        foreach (['VALUE_DESC', 'GAIN_DESC', 'PERFORMANCE_DESC', 'NAME_ASC'] as $sort) {
            $this->client->request('GET', '/api/v1/holdings?sort='.$sort, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(200, $sort);
        }
        foreach (['?sort=value_desc', '?sort=', '?sort[]=VALUE_DESC', '?sort=DROP'] as $query) {
            $this->client->request('GET', '/api/v1/holdings'.$query, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(400, $query);
            self::assertSame('invalid_holdings_query', self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR))['code']);
        }
    }

    public function testTheReadRequiresASession(): void
    {
        $this->client->request('GET', '/api/v1/holdings', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
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

    private function holdETF(string $account, string $quantity, string $unitPrice, string $valuedOn, string $price): void
    {
        $gross = (string) \Brick\Math\BigDecimal::of($quantity)->multipliedBy($unitPrice)->toScale(2);
        $this->send('POST', '/api/v1/accounts/'.$account.'/portfolio-operations', ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => ['value' => '5000.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', '/api/v1/accounts/'.$account.'/portfolio-operations', ['type' => 'BUY', 'tradeDate' => '2026-03-03', 'instrumentId' => self::W,
            'quantity' => $quantity, 'unitPrice' => $unitPrice, 'grossAmount' => ['value' => $gross, 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $this->postValuation($account, ['valuedOn' => $valuedOn, 'detail' => 'POSITIONS', 'prices' => [['instrumentId' => self::W, 'price' => $price, 'priceDate' => $valuedOn]]]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, mixed> $body */
    private function postValuation(string $account, array $body): void
    {
        $this->send('POST', '/api/v1/accounts/'.$account.'/portfolio-valuations', $body);
    }

    /** @return array<string, mixed> */
    private function holdings(): array
    {
        $this->client->request('GET', '/api/v1/holdings', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        return self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_map(self::map(...), $value));
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $typed = [];
        foreach ($value as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }
}
