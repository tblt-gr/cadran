<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
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
