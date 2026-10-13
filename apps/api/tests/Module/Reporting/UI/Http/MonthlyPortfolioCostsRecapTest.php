<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Portfolio fees and taxes appear on the monthly recap as an informative block, outside every budget total. */
final class MonthlyPortfolioCostsRecapTest extends WebTestCase
{
    private const string ACCOUNT = PortfolioFixture::ACCOUNT;
    private const string SECOND = '00000000-0000-7000-8000-0000000000d4';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000d7';
    private const string ETF = PortfolioFixture::ETF;

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

    public function testAWorkspaceWithoutAPortfolioModeAccountHasNoCostsBlock(): void
    {
        $this->portfolio->account('00000000-0000-7000-8000-0000000000d3', label: 'Courant', kind: 'CURRENT', valuationMode: 'TRANSACTIONS');
        $this->portfolio->account(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');

        self::assertNull($this->recap()['portfolioCosts']);
    }

    public function testAPortfolioAccountWithoutOperationsThisMonthYieldsAnEmptyButPresentBlock(): void
    {
        $this->portfolio->account();

        $costs = self::map($this->recap()['portfolioCosts']);

        self::assertSame(['items', 'totalFees', 'totalTaxes', 'assetCode'], array_keys($costs));
        self::assertSame([], $costs['items']);
        self::assertSame(['value' => '0', 'assetCode' => 'EUR'], $costs['totalFees']);
        self::assertSame(['value' => '0', 'assetCode' => 'EUR'], $costs['totalTaxes']);
        self::assertSame('EUR', $costs['assetCode']);
    }

    public function testFeesAndTaxesAreCountedOncePerAccountAndEqualTheCashViewAndTheOperationList(): void
    {
        $this->portfolio->account(label: 'Compte-titres');
        $this->portfolio->account(self::SECOND, label: 'Assurance-vie');
        $this->portfolio->account(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->portfolio->instrument();
        $this->recordReferenceSequence(self::ACCOUNT);
        $this->record(self::SECOND, ['type' => 'FEE', 'tradeDate' => '2026-03-10', 'fees' => $this->eur('0.30')]);
        $this->record(self::ACCOUNT, ['type' => 'FEE', 'tradeDate' => '2026-04-01', 'fees' => $this->eur('9.00')]);

        $costs = self::map($this->recap()['portfolioCosts']);
        $items = self::list($costs['items']);

        self::assertSame(['Assurance-vie', 'Compte-titres'], array_column($items, 'label'));
        self::assertSame(['accountId', 'label', 'assetCode', 'fees', 'taxes'], array_keys($items[1]));
        self::assertSame(self::ACCOUNT, $items[1]['accountId']);
        self::assertSame(['value' => '8.98', 'assetCode' => 'EUR'], $items[1]['fees']);
        self::assertSame(['value' => '4.95', 'assetCode' => 'EUR'], $items[1]['taxes']);
        self::assertSame(['value' => '0.30', 'assetCode' => 'EUR'], $items[0]['fees']);
        self::assertSame(['value' => '0', 'assetCode' => 'EUR'], $items[0]['taxes']);
        self::assertSame(['value' => '9.28', 'assetCode' => 'EUR'], $costs['totalFees']);
        self::assertSame(['value' => '4.95', 'assetCode' => 'EUR'], $costs['totalTaxes']);

        $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-cash?from=2026-03-01&to=2026-03-31', server: ['HTTP_ACCEPT' => 'application/json']);
        $cash = self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($items[1]['fees'], self::map($cash['totals'])['fees']);
        self::assertSame($items[1]['taxes'], self::map($cash['totals'])['taxes']);

        $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-operations?from=2026-03-01&to=2026-03-31&pageSize=100', server: ['HTTP_ACCEPT' => 'application/json']);
        $page = self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $fees = BigDecimal::zero();
        $taxes = BigDecimal::zero();
        foreach (self::list($page['items']) as $operation) {
            $fees = $fees->plus(self::amount($operation['fees']));
            $taxes = $taxes->plus(self::amount($operation['taxes']));
        }
        self::assertSame('8.98', (string) $fees);
        self::assertSame('4.95', (string) $taxes);

        $this->client->request('GET', '/api/v1/reports/monthly/recap?month=2026-04', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $april = self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $aprilCosts = self::map($april['portfolioCosts']);
        $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-summary?asOf=2026-04-30', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $summary = self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $summaryCosts = self::map($summary['costs']);
        self::assertSame((string) self::amount($items[1]['fees'])->plus(self::amount($aprilCosts['totalFees'])), $summaryCosts['fees']);
        self::assertSame((string) self::amount($items[1]['taxes'])->plus(self::amount($aprilCosts['totalTaxes'])), $summaryCosts['taxes']);
    }

    public function testAccountsInDifferentAssetsKeepTheirOwnItemsAndNeverAddUpToAZeroTotal(): void
    {
        $this->portfolio->account(label: 'Compte-titres');
        $this->portfolio->account(self::SECOND, label: 'Brokerage USD', assetCode: 'USD');
        $this->record(self::ACCOUNT, ['type' => 'FEE', 'tradeDate' => '2026-03-10', 'fees' => $this->eur('2.00')]);
        $this->record(self::SECOND, ['type' => 'FEE', 'tradeDate' => '2026-03-11', 'fees' => ['value' => '3.00', 'assetCode' => 'USD']]);

        $costs = self::map($this->recap()['portfolioCosts']);
        $items = self::list($costs['items']);

        self::assertSame(['Brokerage USD', 'Compte-titres'], array_column($items, 'label'));
        self::assertSame(['accountId', 'label', 'assetCode', 'fees', 'taxes'], array_keys($items[0]));
        self::assertSame('USD', $items[0]['assetCode']);
        self::assertSame(['value' => '3.00', 'assetCode' => 'USD'], $items[0]['fees']);
        self::assertSame('EUR', $items[1]['assetCode']);
        self::assertNull($costs['totalFees']);
        self::assertNull($costs['totalTaxes']);
        self::assertNull($costs['assetCode']);
    }

    public function testAVoidedOperationAndAnotherWorkspacesPortfolioNeverAppear(): void
    {
        $this->portfolio->account(label: 'Compte-titres');
        $this->portfolio->account(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->record(self::ACCOUNT, ['type' => 'FEE', 'tradeDate' => '2026-03-10', 'fees' => $this->eur('2.00')]);
        $id = $this->connection->fetchOne("SELECT id FROM portfolio_operations WHERE operation_type = 'FEE'");
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($id).'/void', ['version' => 1, 'reason' => 'Erreur']);
        self::assertResponseStatusCodeSame(200);
        $this->connection->insert('portfolio_operations', [
            'id' => '00000000-0000-7000-8000-0000000000f1', 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'account_id' => self::FOREIGN,
            'operation_type' => 'FEE', 'trade_date' => '2026-03-10', 'sequence' => 1, 'asset_code' => 'EUR', 'fees_value' => '77', 'fees_scale' => 0,
            'taxes_value' => '0', 'taxes_scale' => 0, 'cash_amount_value' => '-77', 'cash_amount_scale' => 0, 'state' => 'ACTIVE',
            'created_at' => '2026-03-02 10:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID, 'version' => 1,
        ]);

        $costs = self::map($this->recap()['portfolioCosts']);

        self::assertSame([], $costs['items']);
        self::assertSame(['value' => '0', 'assetCode' => 'EUR'], $costs['totalFees']);
    }

    public function testRecordingPortfolioOperationsNeverMovesTheBudgetTotals(): void
    {
        $this->portfolio->account(label: 'Compte-titres');
        $this->portfolio->instrument();
        $before = $this->recap();
        $this->client->request('GET', '/api/v1/reports/monthly?month=2026-03', server: ['HTTP_ACCEPT' => 'application/json']);
        $projectionBefore = (string) $this->client->getResponse()->getContent();

        $reportPaths = ['/api/v1/reports/annual/2026?incompleteMonths=include', '/api/v1/reports/multi-year?from=2026&to=2026&incompleteMonths=include'];
        $reportBodies = [];
        foreach ($reportPaths as $path) {
            $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $reportBodies[$path] = (string) $this->client->getResponse()->getContent();
        }

        $this->recordReferenceSequence(self::ACCOUNT);
        $this->record(self::ACCOUNT, ['type' => 'SPLIT', 'tradeDate' => '2026-03-08', 'instrumentId' => self::ETF, 'ratio' => ['numerator' => '2', 'denominator' => '1']]);
        $this->record(self::ACCOUNT, ['type' => 'SECURITY_TRANSFER_OUT', 'tradeDate' => '2026-03-09', 'instrumentId' => self::ETF, 'quantity' => '1']);
        $this->record(self::ACCOUNT, ['type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-03-10', 'instrumentId' => self::ETF, 'quantity' => '1', 'costBasis' => $this->eur('200')]);

        $after = $this->recap();
        self::assertSame($before['totals'], $after['totals'], 'budget expenses, surplus and savings rate are unchanged');
        self::assertSame($before['groups'], $after['groups']);
        self::assertNotSame($before['portfolioCosts'], $after['portfolioCosts'], 'only the costs block changes');
        $this->client->request('GET', '/api/v1/reports/monthly?month=2026-03', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame($projectionBefore, (string) $this->client->getResponse()->getContent());
        foreach ($reportBodies as $path => $body) {
            $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            self::assertSame($body, (string) $this->client->getResponse()->getContent(), $path);
        }
    }

    private function recordReferenceSequence(string $account): void
    {
        $this->record($account, ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('3000.00')]);
        $this->record($account, ['type' => 'BUY', 'tradeDate' => '2026-03-02', 'instrumentId' => self::ETF, 'quantity' => '10', 'unitPrice' => '230.5688', 'grossAmount' => $this->eur('2305.69'), 'fees' => $this->eur('1.99')]);
        $this->record($account, ['type' => 'DIVIDEND', 'tradeDate' => '2026-03-03', 'instrumentId' => self::ETF, 'quantity' => '10', 'grossAmount' => $this->eur('12.50'), 'taxes' => $this->eur('3.75')]);
        $this->record($account, ['type' => 'FEE', 'tradeDate' => '2026-03-04', 'fees' => $this->eur('5.00')]);
        $this->record($account, ['type' => 'TAX', 'tradeDate' => '2026-03-05', 'taxes' => $this->eur('1.20')]);
        $this->record($account, ['type' => 'SELL', 'tradeDate' => '2026-03-06', 'instrumentId' => self::ETF, 'quantity' => '4', 'unitPrice' => '250.10', 'grossAmount' => $this->eur('1000.40'), 'fees' => $this->eur('1.99')]);
        $this->record($account, ['type' => 'WITHDRAWAL', 'tradeDate' => '2026-03-07', 'grossAmount' => $this->eur('500.00')]);
    }

    /** @param array<string, mixed> $body */
    private function record(string $account, array $body): void
    {
        $this->send('POST', '/api/v1/accounts/'.$account.'/portfolio-operations', $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function recap(): array
    {
        $this->client->request('GET', '/api/v1/reports/monthly/recap?month=2026-03', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return self::map(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
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

    private static function amount(mixed $amount): BigDecimal
    {
        if (null === $amount) {
            return BigDecimal::zero();
        }
        $fields = self::map($amount);

        return BigDecimal::of(self::string($fields['value']));
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
