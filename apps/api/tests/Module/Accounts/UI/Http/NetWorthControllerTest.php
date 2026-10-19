<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The published net worth over HTTP.
 *
 * What a screen and a later statement may rely on: the total is the signed
 * sum of the included accounts, a missing figure is null with a reason rather
 * than a zero, the change rate disappears on a zero or negative base, and
 * another workspace contributes nothing and leaks nothing.
 */
final class NetWorthControllerTest extends WebTestCase
{
    private const string CASH = '00000000-0000-7000-8000-0000000000e1';
    private const string LIVRET = '00000000-0000-7000-8000-0000000000e2';
    private const string LOAN = '00000000-0000-7000-8000-0000000000e3';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000e9';
    private const string LIQUID = '00000000-0000-7000-8000-0000000000b1';
    private const string SAVINGS = '00000000-0000-7000-8000-0000000000b2';
    private const string ASK = '/api/v1/net-worth?asOf=2026-09-05&comparedTo=2026-08-05';

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
        $this->resetLoginThrottling();

        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testAnAnonymousCallerReadsNothing(): void
    {
        $this->client->request('GET', self::ASK);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheTotalSumsSignedIncludedValuesAndNamesEverySource(): void
    {
        $this->signIn();
        $this->seedPortfolio();

        $netWorth = $this->read(self::ASK);

        $delta = self::fields((array) $netWorth['delta']);
        self::assertSame('6.666666666666666666666700', $delta['ratePercent']);
        self::assertSame('6.67', $delta['ratePercentDisplay']);

        self::assertSame('2026-09-05', $netWorth['asOf']);
        self::assertSame(
            ['value' => '80000.00', 'assetCode' => 'EUR'],
            ['value' => self::amount($netWorth, 'total'), 'assetCode' => 'EUR'],
        );
        self::assertNull($netWorth['reason']);
        self::assertSame('CURRENT', $netWorth['quality']);
        self::assertSame(3, $netWorth['eligibleAccountCount']);

        $contributions = $this->rows($netWorth, 'contributions');
        $loan = $this->byKey($contributions, 'accountId')[self::LOAN];
        self::assertSame(-1, $loan['netWorthSign']);
        self::assertSame('20000.00', self::nested($loan, 'amount', 'value'));
        self::assertSame('-20000.00', self::nested($loan, 'signedAmount', 'value'));
    }

    public function testTheExclusiveAllocationRollsAChildIntoItsParentOnce(): void
    {
        $this->signIn();
        $this->seedPortfolio();

        $allocation = $this->byKey($this->rows($this->read(self::ASK), 'allocation'), 'groupId');

        self::assertSame('100000.00', self::nested($allocation[self::LIQUID], 'value', 'value'));
        self::assertSame('40000.00', self::nested($allocation[self::SAVINGS], 'value', 'value'));
        self::assertSame(
            '125.000000000000000000000000',
            self::nested($allocation[self::LIQUID], 'share', 'percent'),
        );
        self::assertSame('125.00', self::nested($allocation[self::LIQUID], 'share', 'percentDisplay'));
    }

    public function testTheDeltaKeepsItsAmountAndDropsTheRateOnANegativeBase(): void
    {
        $this->signIn();
        $this->insertAccount(self::LOAN, WorkspaceFixture::OWN_WORKSPACE, 'Prêt', kind: 'LIABILITY');
        $this->insertSnapshot(self::LOAN, '2026-08-05', '200000.00');
        $this->insertSnapshot(self::LOAN, '2026-09-05', '190000.00');

        $raw = $this->read(self::ASK)['delta'];
        self::assertIsArray($raw);
        $delta = self::fields($raw);

        self::assertSame('-200000.00', self::nested($delta, 'previousTotal', 'value'));
        self::assertSame('10000.00', self::nested($delta, 'amount', 'value'));
        self::assertNull($delta['amountReason']);
        self::assertNull($delta['rate']);
        self::assertNull($delta['ratePercent']);
        self::assertNull($delta['ratePercentDisplay']);
        self::assertSame('NEGATIVE_BASE', $delta['rateReason']);
    }

    public function testAMissingValuationIsPublishedAsAReasonAndNeverAsZero(): void
    {
        $this->signIn();
        $this->insertAccount(self::CASH, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant');

        $netWorth = $this->read(self::ASK);

        self::assertNull($netWorth['total']);
        self::assertSame('MISSING_VALUATION', $netWorth['reason']);
        self::assertSame('MISSING', $netWorth['quality']);
        self::assertStringNotContainsString('"total":{"value":"0"', (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherWorkspaceContributesNothingAndLeaksNothing(): void
    {
        $this->signIn();
        $this->insertAccount(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Private label');
        $this->insertSnapshot(self::FOREIGN, '2026-09-05', '999999.00', WorkspaceFixture::OTHER_WORKSPACE);

        $netWorth = $this->read(self::ASK);

        self::assertNull($netWorth['total']);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', $netWorth['reason']);
        self::assertSame([], $netWorth['contributions']);
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Private label', $body);
        self::assertStringNotContainsString('999999.00', $body);
    }

    public function testTheHistoryEndsOnTheRequestedDayAndKeepsUncomputableMonths(): void
    {
        $this->signIn();
        $this->insertAccount(self::CASH, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant');
        $this->insertSnapshot(self::CASH, '2026-08-15', '12000.00');
        $this->insertSnapshot(self::CASH, '2026-09-05', '12500.00');

        $history = $this->read('/api/v1/net-worth/history?asOf=2026-09-05&months=3');

        self::assertSame('MONTH', $history['granularity']);
        $points = $this->rows($history, 'points');
        self::assertSame(['2026-07-31', '2026-08-31', '2026-09-05'], array_column($points, 'on'));
        self::assertNull($points[0]['total']);
        self::assertSame('MISSING_VALUATION', $points[0]['reason']);
        self::assertSame('12000.00', self::nested($points[1], 'total', 'value'));
        self::assertSame('STALE', $points[1]['quality']);
        self::assertSame('12500.00', self::nested($points[2], 'total', 'value'));
    }

    public function testNamedHistoryPeriodsAndLegacyMetadata(): void
    {
        $this->signIn();
        $this->insertAccount(self::CASH, WorkspaceFixture::OWN_WORKSPACE, 'Compte');
        $this->insertSnapshot(self::CASH, '2026-07-15', '12000.00');
        $all = $this->read('/api/v1/net-worth/history?asOf=2026-09-05&period=ALL');
        self::assertSame('ALL', $all['period']);
        self::assertSame('2026-07', $all['firstDataMonth']);
        self::assertFalse($all['truncated']);
        self::assertSame(['2026-07-31', '2026-08-31', '2026-09-05'], array_column($this->rows($all, 'points'), 'on'));
        $legacy = $this->read('/api/v1/net-worth/history?asOf=2026-09-05&months=12');
        self::assertNull($legacy['period']);
        $default = $this->read('/api/v1/net-worth/history?asOf=2026-09-05');
        self::assertSame('LAST_12_MONTHS', $default['period']);
        self::assertSame($legacy['points'], $default['points']);
        $january = $this->read('/api/v1/net-worth/history?asOf=2026-01-07&period=YTD');
        self::assertCount(1, $this->rows($january, 'points'));
    }

    public function testAllHistoryExcludesForeignFutureAndSupersededSnapshots(): void
    {
        $this->signIn();
        $this->insertAccount(self::CASH, WorkspaceFixture::OWN_WORKSPACE, 'Compte');
        $this->insertAccount(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Private');
        $this->insertSnapshot(self::FOREIGN, '2000-01-01', '999999.00', WorkspaceFixture::OTHER_WORKSPACE);
        $this->insertSnapshot(self::CASH, '2027-01-01', '1.00');
        $this->insertSnapshot(self::CASH, '2020-01-01', '1.00');
        $this->connection->update('account_balance_snapshots', ['superseded_at' => '2026-09-05 12:00:00+00'], [
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::CASH, 'as_of' => '2020-01-01',
        ]);
        $empty = $this->read('/api/v1/net-worth/history?asOf=2026-09-05&period=ALL');
        self::assertNull($empty['firstDataMonth']);
        self::assertCount(1, $this->rows($empty, 'points'));
        self::assertNull($this->rows($empty, 'points')[0]['total']);
        $this->insertSnapshot(self::CASH, '2026-08-15', '12.00');
        self::assertSame('2026-08', $this->read('/api/v1/net-worth/history?asOf=2026-09-05&period=ALL')['firstDataMonth']);
    }

    public function testAllStartsFromBookedTransactionsButExcludesVoidedPendingFutureAndForeignRows(): void
    {
        $this->signIn();
        $this->insertAccount(self::CASH, WorkspaceFixture::OWN_WORKSPACE, 'Compte');
        $this->insertAccount(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Private');
        $fixture = new PortfolioFixture($this->connection);
        $fixture->transferLeg('00000000-0000-7000-8000-000000001001', self::CASH, '1.00', '2020-01-01', state: 'VOIDED');
        $fixture->transferLeg('00000000-0000-7000-8000-000000001002', self::CASH, '1.00', '2020-02-01', state: 'PENDING');
        $fixture->transferLeg('00000000-0000-7000-8000-000000001003', self::CASH, '1.00', '2027-01-01');
        $fixture->transferLeg('00000000-0000-7000-8000-000000001004', self::FOREIGN, '1.00', '2000-01-01', workspace: WorkspaceFixture::OTHER_WORKSPACE);
        $empty = $this->read('/api/v1/net-worth/history?asOf=2026-09-05&period=ALL');
        self::assertNull($empty['firstDataMonth']);
        $fixture->transferLeg('00000000-0000-7000-8000-000000001005', self::CASH, '1.00', '2026-06-05');
        $history = $this->read('/api/v1/net-worth/history?asOf=2026-09-05&period=ALL');
        self::assertSame('2026-06', $history['firstDataMonth']);
        self::assertCount(4, $this->rows($history, 'points'));
        $this->insertSnapshot(self::CASH, '2026-05-15', '12.00');
        self::assertSame('2026-05', $this->read('/api/v1/net-worth/history?asOf=2026-09-05&period=ALL')['firstDataMonth']);
    }

    public function testAllStartsFromActivePortfolioValuationsAndPublishesTruncation(): void
    {
        $this->signIn();
        $this->insertAccount(self::CASH, WorkspaceFixture::OWN_WORKSPACE, 'Compte');
        $this->insertAccount(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Private');
        $this->insertPortfolioValuation('00000000-0000-7000-8000-000000002001', '2000-01-01', self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE);
        $this->insertPortfolioValuation('00000000-0000-7000-8000-000000002002', '2027-01-01');
        $this->insertPortfolioValuation('00000000-0000-7000-8000-000000002003', '2019-01-01');
        $this->connection->update('portfolio_valuations', ['superseded_at' => '2026-09-05 12:00:00+00'], [
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => '00000000-0000-7000-8000-000000002003',
        ]);
        self::assertNull($this->read('/api/v1/net-worth/history?asOf=2026-09-05&period=ALL')['firstDataMonth']);
        $this->insertPortfolioValuation('00000000-0000-7000-8000-000000002004', '2026-07-10');
        $history = $this->read('/api/v1/net-worth/history?asOf=2026-09-05&period=ALL');
        self::assertSame('2026-07', $history['firstDataMonth']);
        self::assertCount(3, $this->rows($history, 'points'));
        $this->insertPortfolioValuation('00000000-0000-7000-8000-000000002005', '2020-01-10');
        $truncated = $this->read('/api/v1/net-worth/history?asOf=2026-09-05&period=ALL');
        self::assertTrue($truncated['truncated']);
        self::assertSame('2020-01', $truncated['firstDataMonth']);
        self::assertCount(60, $this->rows($truncated, 'points'));
        self::assertSame('2021-10-31', $this->rows($truncated, 'points')[0]['on']);
    }

    public function testInvalidNamedPeriodsAndCombinedParametersReturn422(): void
    {
        $this->signIn();
        foreach (['period=invalid', 'period=', 'period[]=ALL', 'period=ALL&months=12', 'period=YTD&months=', 'period=ALL&months[]=12'] as $query) {
            $this->client->request('GET', '/api/v1/net-worth/history?'.$query);
            self::assertResponseStatusCodeSame(422);
            self::assertResponseHeaderSame('content-type', 'application/problem+json');
        }
        $this->client->request('GET', '/api/v1/net-worth/history?period=ALL&asOf=2026-02-31');
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnonymousHistoryAccessIsRefused(): void
    {
        $this->client->request('GET', '/api/v1/net-worth/history?period=ALL');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAMalformedOrOutOfBoundsQueryIsRefused(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/v1/net-worth?asOf=05-09-2026');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/net-worth?asOf=2026-02-31');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/net-worth?asOf=2026-09-05&comparedTo=2026-09-05');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/net-worth/history?months=61');
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    private function seedPortfolio(): void
    {
        $this->insertGroup(self::LIQUID, 'Liquidités');
        $this->insertGroup(self::SAVINGS, 'Épargne', parentId: self::LIQUID, depth: 2);
        $this->insertAccount(self::CASH, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant', groupId: self::LIQUID);
        $this->insertAccount(self::LIVRET, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', groupId: self::SAVINGS);
        $this->insertAccount(self::LOAN, WorkspaceFixture::OWN_WORKSPACE, 'Prêt immobilier', kind: 'LIABILITY');

        $this->insertSnapshot(self::CASH, '2026-08-05', '55000.00');
        $this->insertSnapshot(self::CASH, '2026-09-05', '60000.00');
        $this->insertSnapshot(self::LIVRET, '2026-08-05', '40000.00');
        $this->insertSnapshot(self::LIVRET, '2026-09-05', '40000.00');
        $this->insertSnapshot(self::LOAN, '2026-08-05', '20000.00');
        $this->insertSnapshot(self::LOAN, '2026-09-05', '20000.00');
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function read(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return self::fields($decoded);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? null;
        self::assertIsList($rows);

        return array_map(static function (mixed $row): array {
            self::assertIsArray($row);

            return self::fields($row);
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $rows, string $key): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            self::assertArrayHasKey($key, $row);
            self::assertIsString($row[$key]);
            $indexed[$row[$key]] = $row;
        }

        return $indexed;
    }

    /** @param array<string, mixed> $payload */
    private static function amount(array $payload, string $key): string
    {
        return self::nested($payload, $key, 'value');
    }

    /** @param array<string, mixed> $payload */
    private static function nested(array $payload, string $key, string $inner): string
    {
        self::assertArrayHasKey($key, $payload);
        $value = $payload[$key];
        self::assertIsArray($value);
        self::assertArrayHasKey($inner, $value);
        self::assertIsString($value[$inner]);

        return $value[$inner];
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<string, mixed>
     */
    private static function fields(array $decoded): array
    {
        $typed = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $typed[$key] = $value;
        }

        return $typed;
    }

    private function insertGroup(string $id, string $label, ?string $parentId = null, int $depth = 1): void
    {
        $this->connection->insert('account_groups', [
            'id' => $id,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'label' => $label,
            'parent_id' => $parentId,
            'sort_order' => 0,
            'depth' => $depth,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
        ]);
    }

    private function insertAccount(
        string $id,
        string $workspaceId,
        string $label,
        string $kind = 'CURRENT',
        ?string $groupId = null,
    ): void {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'label' => $label,
            'asset_code' => 'EUR',
            'kind' => $kind,
            'product_code' => null,
            'product_model_id' => null,
            'institution' => null,
            'masked_identifier' => null,
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => true,
            'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-10',
            'closed_on' => null,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
            'primary_group_id' => $groupId,
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function insertSnapshot(
        string $accountId,
        string $asOf,
        string $amount,
        string $workspaceId = WorkspaceFixture::OWN_WORKSPACE,
    ): void {
        $this->connection->insert('account_balance_snapshots', [
            'id' => substr(sha1($accountId.$asOf), 0, 8).'-0000-7000-8000-000000000000',
            'workspace_id' => $workspaceId,
            'account_id' => $accountId,
            'as_of' => $asOf,
            'amount_value' => $amount,
            'amount_literal' => $amount,
            'amount_asset' => 'EUR',
            'source' => 'MANUAL',
            'reconciliation_status' => 'UNRECONCILED',
            'comment' => null,
            'version' => 1,
            'recorded_at' => $asOf.' 12:00:00+00',
            'recorded_by' => WorkspaceFixture::OWNER_ID,
            'superseded_at' => null,
        ]);
    }

    private function insertPortfolioValuation(string $id, string $on, string $account = self::CASH, string $workspace = WorkspaceFixture::OWN_WORKSPACE): void
    {
        $this->connection->insert('portfolio_valuations', [
            'id' => $id, 'workspace_id' => $workspace, 'account_id' => $account,
            'valued_on' => $on, 'asset_code' => 'EUR', 'cash_value' => '1.00', 'cash_scale' => 2,
            'derived_cash_value' => '1.00', 'derived_cash_scale' => 2, 'cash_source' => 'DERIVED',
            'detail' => 'TOTAL', 'completeness' => 'PARTIAL', 'source' => 'MANUAL',
            'recorded_at' => '2026-09-05 12:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function resetLoginThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}
