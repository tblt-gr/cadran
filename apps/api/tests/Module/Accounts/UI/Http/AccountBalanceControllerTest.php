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
 * Recording a dated observed balance and reading the latest valid figure.
 *
 * What a screen and a later statement may rely on: the source literal stays
 * exact, display rounding is applied by the backend, a missing valuation is
 * null rather than zero, and another workspace cannot see the row.
 */
final class AccountBalanceControllerTest extends WebTestCase
{
    private const string LIVRET = '00000000-0000-7000-8000-0000000000e1';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000e5';

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
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testAManualBalanceIsRecordedAndReadBackWithSourceAndDisplayKeptApart(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET, WorkspaceFixture::OWN_WORKSPACE, 'Livret A');

        $recorded = $this->record(self::LIVRET, $this->body('2026-09-03', '230.5688'));
        self::assertResponseStatusCodeSame(201);
        self::assertSame([
            'id', 'accountId', 'asOf', 'amount', 'source', 'reconciliationStatus',
            'comment', 'active', 'version', 'recordedAt', 'supersededAt',
        ], array_keys($recorded));
        self::assertSame(['value' => '230.5688', 'assetCode' => 'EUR'], $recorded['amount']);
        self::assertSame('MANUAL', $recorded['source']);
        self::assertSame('UNRECONCILED', $recorded['reconciliationStatus']);
        self::assertTrue($recorded['active']);
        self::assertSame(1, $recorded['version']);

        $valuation = $this->valuation(self::LIVRET, '2026-09-05');
        self::assertSame([
            'accountId', 'requestedOn', 'asOf', 'amount', 'display', 'belowDisplayStep',
            'source', 'ageDays', 'quality', 'reconciliationStatus', 'snapshotId', 'version',
        ], array_keys($valuation));
        self::assertSame(self::LIVRET, $valuation['accountId']);
        self::assertSame('2026-09-05', $valuation['requestedOn']);
        self::assertSame('2026-09-03', $valuation['asOf']);
        self::assertSame(['value' => '230.5688', 'assetCode' => 'EUR'], $valuation['amount']);
        self::assertSame(['value' => '230.57', 'assetCode' => 'EUR'], $valuation['display']);
        self::assertFalse($valuation['belowDisplayStep']);
        self::assertSame('MANUAL', $valuation['source']);
        self::assertSame(2, $valuation['ageDays']);
        self::assertSame('STALE', $valuation['quality']);
        self::assertSame($recorded['id'], $valuation['snapshotId']);

        $listed = $this->items('/api/v1/accounts?asOf=2026-09-05');
        self::assertCount(1, $listed);
        self::assertSame($valuation, $listed[0]['valuation']);
    }

    public function testAMissingValuationIsNullNeverASilentZero(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET, WorkspaceFixture::OWN_WORKSPACE, 'Livret A');

        $valuation = $this->valuation(self::LIVRET, '2026-09-05');
        self::assertNull($valuation['amount']);
        self::assertNull($valuation['display']);
        self::assertNull($valuation['asOf']);
        self::assertNull($valuation['source']);
        self::assertNull($valuation['ageDays']);
        self::assertSame('MISSING', $valuation['quality']);
        self::assertFalse($valuation['belowDisplayStep']);
    }

    public function testAZeroBalanceIsARecordedFigureNotAMissingOne(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET, WorkspaceFixture::OWN_WORKSPACE, 'Livret A');
        $this->record(self::LIVRET, $this->body('2026-09-03', '0'));

        $valuation = $this->valuation(self::LIVRET, '2026-09-03');
        self::assertSame(['value' => '0', 'assetCode' => 'EUR'], $valuation['amount']);
        self::assertSame('CURRENT', $valuation['quality']);
        self::assertSame(0, $valuation['ageDays']);
    }

    public function testReplacingTheSameDayRequiresTheCurrentVersion(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET, WorkspaceFixture::OWN_WORKSPACE, 'Livret A');
        $first = $this->record(self::LIVRET, $this->body('2026-09-03', '230.5688'));

        $this->requestRecord(self::LIVRET, $this->body('2026-09-03', '231.10'));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString(
            'account-balance-conflict',
            (string) $this->client->getResponse()->getContent(),
        );

        $replaced = $this->record(self::LIVRET, $this->body('2026-09-03', '231.10', version: 1));
        self::assertSame(['value' => '231.10', 'assetCode' => 'EUR'], $replaced['amount']);
        self::assertTrue($replaced['active']);
        self::assertSame(1, $replaced['version']);

        $this->requestRecord(self::LIVRET, $this->body('2026-09-03', '240.00', version: 2));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString(
            'stale-version',
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/v1/accounts/'.self::LIVRET.'/balances?page=1&perPage=1');
        $page = $this->decode();
        self::assertSame(1, $page['page']);
        self::assertSame(1, $page['perPage']);
        self::assertSame(2, $page['total']);
        $items = $page['items'] ?? null;
        self::assertIsList($items);
        self::assertCount(1, $items);
        self::assertIsArray($items[0]);
        self::assertTrue($items[0]['active']);

        $history = $this->items('/api/v1/accounts/'.self::LIVRET.'/balances');
        self::assertCount(2, $history);
        self::assertTrue($history[0]['active']);
        self::assertFalse($history[1]['active']);
        self::assertSame($first['id'], $history[1]['id']);
    }

    public function testSnapshotQueryBoundsAreEnforced(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET, WorkspaceFixture::OWN_WORKSPACE, 'Livret A');

        $this->client->request('GET', '/api/v1/accounts/'.self::LIVRET.'/balances?page=0');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts/'.self::LIVRET.'/balances?perPage=101');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts/'.self::LIVRET.'/balances?page=1001');
        self::assertResponseStatusCodeSame(400);
    }

    public function testEditingAnAccountKeepsTheCurrentValuation(): void
    {
        $this->signIn();
        $account = $this->createAccount('Livret A');
        $this->record(self::stringValue($account, 'id'), $this->body('2026-09-03', '230.5688'));

        $listed = $this->items('/api/v1/accounts');
        $current = $listed[0];
        $valuation = $current['valuation'] ?? null;
        self::assertIsArray($valuation);
        self::assertSame(['value' => '230.5688', 'assetCode' => 'EUR'], $valuation['amount']);
        self::assertNotSame('MISSING', $valuation['quality']);

        $this->client->request(
            'PUT',
            '/api/v1/accounts/'.self::stringValue($current, 'id'),
            server: self::jsonHeaders(),
            content: json_encode([
                'label' => 'Livret A Banque Y',
                'kind' => $current['kind'],
                'productCode' => $current['productCode'],
                'productModelId' => $current['productModelId'],
                'institution' => $current['institution'],
                'maskedIdentifier' => $current['maskedIdentifier'],
                'valuationMode' => $current['valuationMode'],
                'liquidityLevel' => $current['liquidityLevel'],
                'includeInNetWorth' => $current['includeInNetWorth'],
                'includeInEmergencyFund' => $current['includeInEmergencyFund'],
                'openedOn' => $current['openedOn'],
                'closedOn' => $current['closedOn'],
                'version' => $current['version'],
                'primaryGroupId' => $current['primaryGroupId'],
                'tagGroupIds' => $current['tagGroupIds'],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $edited = $this->decode();
        $kept = $edited['valuation'] ?? null;
        self::assertIsArray($kept);
        self::assertSame(['value' => '230.5688', 'assetCode' => 'EUR'], $kept['amount']);
        self::assertNotSame('MISSING', $kept['quality']);
        self::assertSame('2026-09-03', $kept['asOf']);
    }

    public function testAnotherWorkspaceCannotReadOrRecordABalance(): void
    {
        $this->signIn();
        $this->insertAccount(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Compte voisin');

        $this->client->request('GET', '/api/v1/accounts/'.self::FOREIGN.'/valuation?asOf=2026-09-05');
        self::assertResponseStatusCodeSame(404);

        $this->requestRecord(self::FOREIGN, $this->body('2026-09-03', '100'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countSnapshots());
    }

    public function testAnArchivedAccountRefusesANewSnapshot(): void
    {
        $this->signIn();
        $account = $this->createAccount('Livret A');
        $id = self::stringValue($account, 'id');
        $this->client->request(
            'POST',
            '/api/v1/accounts/'.$id.'/archive',
            server: self::jsonHeaders(),
            content: json_encode(['version' => $account['version']], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $this->requestRecord($id, $this->body('2026-09-03', '230.5688'));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'account-archived',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testExcessPrecisionAndAForeignAssetAreRefused(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET, WorkspaceFixture::OWN_WORKSPACE, 'Livret A');

        $this->requestRecord(self::LIVRET, $this->body('2026-09-03', '230.568812345'));
        self::assertResponseStatusCodeSame(422);

        $this->requestRecord(self::LIVRET, $this->body('2026-09-03', '230.5688', asset: 'USD'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countSnapshots());
    }

    public function testTheAuditTrailNamesTheDayAndSourceButNeverTheFigure(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET, WorkspaceFixture::OWN_WORKSPACE, 'Livret A');
        $recorded = $this->record(self::LIVRET, $this->body(
            '2026-09-03',
            '230.5688',
            comment: 'Relevé du 3 septembre.',
        ));

        $event = $this->connection->fetchAssociative(
            "SELECT after_json FROM audit_events
             WHERE workspace_id = ? AND event_type = 'account_balance_snapshot.recorded' AND entity_id = ?",
            [WorkspaceFixture::OWN_WORKSPACE, $recorded['id']],
        );
        self::assertIsArray($event);
        self::assertIsString($event['after_json']);
        self::assertStringNotContainsString('230.5688', $event['after_json']);
        self::assertStringNotContainsString('Relevé', $event['after_json']);
        $fingerprint = json_decode($event['after_json'], true);
        self::assertIsArray($fingerprint);
        self::assertSame('2026-09-03', $fingerprint['asOf']);
        self::assertSame('MANUAL', $fingerprint['source']);
        self::assertSame('true', $fingerprint['commented']);
        self::assertArrayNotHasKey('amount', $fingerprint);
        self::assertArrayNotHasKey('comment', $fingerprint);
        self::assertArrayNotHasKey('accountId', $fingerprint);
    }

    public function testAMutationWithoutACsrfTokenIsRefused(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET, WorkspaceFixture::OWN_WORKSPACE, 'Livret A');
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');

        $this->requestRecord(self::LIVRET, $this->body('2026-09-03', '230.5688'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countSnapshots());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $asOf, string $amount, ?string $comment = null, ?int $version = null, string $asset = 'EUR'): array
    {
        return [
            'asOf' => $asOf,
            'amount' => $amount,
            'amountAssetCode' => $asset,
            'comment' => $comment,
            'version' => $version,
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function record(string $accountId, array $body): array
    {
        $this->requestRecord($accountId, $body);
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function requestRecord(string $accountId, array $body): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts/'.rawurlencode($accountId).'/balances',
            server: self::jsonHeaders(),
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function valuation(string $accountId, string $asOf): array
    {
        $this->client->request('GET', '/api/v1/accounts/'.rawurlencode($accountId).'/valuation?asOf='.$asOf);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /**
     * @return array<string, mixed>
     */
    private function createAccount(string $label): array
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts',
            server: self::jsonHeaders(),
            content: json_encode([
                'label' => $label,
                'assetCode' => 'EUR',
                'kind' => 'SAVINGS',
                'productCode' => null,
                'productModelId' => null,
                'institution' => null,
                'maskedIdentifier' => null,
                'valuationMode' => 'TRANSACTIONS',
                'liquidityLevel' => 'IMMEDIATE',
                'includeInNetWorth' => true,
                'includeInEmergencyFund' => false,
                'openedOn' => '2026-01-10',
                'closedOn' => null,
                'primaryGroupId' => null,
                'tagGroupIds' => [],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @return list<array<string, mixed>> */
    private function items(string $path): array
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $items = $this->decode()['items'] ?? null;
        self::assertIsList($items);

        return array_map(function (mixed $item): array {
            self::assertIsArray($item);

            return $this->fields($item);
        }, $items);
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(mixed $row): array
    {
        self::assertIsArray($row);
        $typed = [];
        foreach ($row as $field => $value) {
            self::assertIsString($field);
            $typed[$field] = $value;
        }

        return $typed;
    }

    private function signIn(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    private function insertAccount(string $id, string $workspaceId, string $label): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'label' => $label,
            'asset_code' => 'EUR',
            'kind' => 'SAVINGS',
            'product_code' => 'FR_LIVRET_A',
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
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $this->fields($decoded);
    }

    /** @param array<string, mixed> $data */
    private static function stringValue(array $data, string $key): string
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsString($data[$key]);

        return $data[$key];
    }

    private function countSnapshots(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM account_balance_snapshots');
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
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
