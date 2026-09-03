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

final class AccountControllerTest extends WebTestCase
{
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
        $this->signIn();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testTheOwnerCreatesEditsClosesAndArchivesAnAccount(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X');
        $id = self::stringValue($account, 'id');

        // The published field set, asserted whole: a view field added without a
        // matching schema change would otherwise reach clients unnoticed.
        self::assertSame([
            'id', 'label', 'assetCode', 'kind', 'maskedIdentifier', 'valuationMode', 'liquidityLevel',
            'includeInNetWorth', 'includeInEmergencyFund', 'openedOn', 'closedOn', 'status',
            'netWorthSign', 'used', 'editable', 'kindEditable', 'kindEditReason', 'version', 'archivedAt',
        ], array_keys($account));
        self::assertSame('EUR', $account['assetCode']);
        self::assertSame('SAVINGS', $account['kind']);
        self::assertSame('ACTIVE', $account['status']);
        self::assertSame(1, $account['netWorthSign']);
        self::assertTrue($account['kindEditable']);
        self::assertNull($account['closedOn']);

        $this->requestUpdate($id, [...$account, 'label' => 'Livret A Banque Y', 'liquidityLevel' => 'SHORT_TERM']);
        self::assertResponseIsSuccessful();
        $edited = $this->decode();
        self::assertSame('Livret A Banque Y', $edited['label']);
        self::assertSame('SHORT_TERM', $edited['liquidityLevel']);
        self::assertSame(2, $edited['version']);

        $this->requestUpdate($id, [...$edited, 'closedOn' => '2026-02-01']);
        self::assertResponseIsSuccessful();
        $closed = $this->decode();
        self::assertSame('CLOSED', $closed['status']);
        self::assertSame('2026-02-01', $closed['closedOn']);

        $this->requestArchive($id, self::intValue($closed, 'version'));
        self::assertResponseIsSuccessful();
        $archived = $this->decode();
        self::assertSame('ARCHIVED', $archived['status']);
        self::assertFalse($archived['editable']);
        self::assertNotNull($archived['archivedAt']);
    }

    public function testALiabilityIsSignedNegativelyForNetWorth(): void
    {
        $loan = $this->createAccount(label: 'Prêt immobilier', overrides: [
            'kind' => 'LIABILITY',
            'liquidityLevel' => 'ILLIQUID',
            'valuationMode' => 'SNAPSHOTS',
        ]);

        self::assertSame(-1, $loan['netWorthSign']);
    }

    public function testClosedAndArchivedAccountsAreRequestedExplicitly(): void
    {
        $open = $this->createAccount(label: 'Compte courant', overrides: ['kind' => 'CURRENT']);
        $closed = $this->createAccount(label: 'Ancien livret', overrides: ['closedOn' => '2026-02-01']);

        $this->client->request('GET', '/api/v1/accounts');
        self::assertSame(['Compte courant'], array_column($this->items(), 'label'));

        $this->client->request('GET', '/api/v1/accounts?includeClosed=true');
        self::assertCount(2, $this->items());

        $this->requestArchive(self::stringValue($closed, 'id'), self::intValue($closed, 'version'));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/accounts?includeClosed=true');
        self::assertSame(['Compte courant'], array_column($this->items(), 'label'));
        $this->client->request('GET', '/api/v1/accounts?includeClosed=true&includeArchived=true');
        self::assertCount(2, $this->items());

        $this->client->request('GET', '/api/v1/accounts?kind=CURRENT');
        self::assertSame([self::stringValue($open, 'id')], array_column($this->items(), 'id'));
    }

    public function testAnArchivedAccountIsReportedAsReadOnlyRatherThanConflicting(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X');
        $id = self::stringValue($account, 'id');
        $this->requestArchive($id, self::intValue($account, 'version'));
        self::assertResponseIsSuccessful();
        $archived = $this->decode();

        // The archive frees the label, so an active namesake exists while the archived row is edited.
        $this->createAccount(label: 'Livret A Banque X');

        $this->requestUpdate($id, $archived);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/account-archived', $this->decode()['type'] ?? null);

        $this->requestArchive($id, self::intValue($archived, 'version'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/account-archived', $this->decode()['type'] ?? null);
    }

    public function testAnActiveLabelIsUniqueIgnoringCaseWithoutEchoingIt(): void
    {
        $this->createAccount(label: 'Livret A Banque X');

        $this->requestCreate($this->payload('livret a banque x'));

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('/problems/account-label-taken', $this->decode()['type'] ?? null);
        self::assertStringNotContainsString('livret a banque x', mb_strtolower((string) $this->client->getResponse()->getContent()));
    }

    public function testInvalidLifecycleValuationAndIdentifierAreRejected(): void
    {
        $this->requestCreate($this->payload('Compte', ['openedOn' => '2999-01-01']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['openedOn' => '2026-02-01', 'closedOn' => '2026-01-01']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['openedOn' => '10/01/2026']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['valuationMode' => 'PORTFOLIO', 'kind' => 'CURRENT']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['maskedIdentifier' => 'FR7630006000011234567890189']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['includeInNetWorth' => false, 'includeInEmergencyFund' => true]));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['assetCode' => 'ZZZ']));
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->ownAccountCount());
    }

    public function testMassAssignmentAndUnknownFieldsAreRefused(): void
    {
        $body = $this->payload('Compte');
        $body['workspaceId'] = WorkspaceFixture::OTHER_WORKSPACE;
        $this->requestCreate($body);
        self::assertResponseStatusCodeSame(422);

        $body = $this->payload('Compte');
        $body['version'] = 5;
        $this->requestCreate($body);
        self::assertResponseStatusCodeSame(422);

        $account = $this->createAccount(label: 'Livret A Banque X');
        $update = $this->updateBody($account);
        $update['assetCode'] = 'USD';
        $this->client->request(
            'PUT',
            '/api/v1/accounts/'.self::stringValue($account, 'id'),
            server: self::jsonHeaders(),
            content: json_encode($update, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('EUR', $this->connection->fetchOne(
            'SELECT asset_code FROM account_financial_accounts WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, self::stringValue($account, 'id')],
        ));
    }

    public function testAForeignAccountAnswersExactlyLikeAnUnknownOne(): void
    {
        $foreignId = '00000000-0000-7000-8000-0000000000df';
        $this->insertAccount($foreignId, WorkspaceFixture::OTHER_WORKSPACE, 'Private label');

        $body = $this->payload('Changed');
        unset($body['assetCode']);
        $body['version'] = 1;
        $this->client->request('PUT', '/api/v1/accounts/'.$foreignId, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();

        $this->client->request('PUT', '/api/v1/accounts/00000000-0000-7000-8000-0000000000de', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());

        $this->requestArchive($foreignId, 1);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/accounts?includeArchived=true&includeClosed=true');
        self::assertSame([], $this->items());
    }

    public function testAnUpdateUsesOptimisticVersioning(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X');
        $id = self::stringValue($account, 'id');

        $this->requestUpdate($id, [...$account, 'label' => 'Livret A Banque Y']);
        self::assertResponseIsSuccessful();

        // A stale version and a taken label are both 409 and ask for opposite
        // recoveries, so the type is what a client branches on.
        $this->requestUpdate($id, [...$account, 'label' => 'Onglet périmé']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-version', $this->decode()['type'] ?? null);

        $this->requestArchive($id, self::intValue($account, 'version'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-version', $this->decode()['type'] ?? null);
    }

    public function testAUsedAccountCannotChangeKind(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X');
        $id = self::stringValue($account, 'id');
        $this->connection->update(
            'account_financial_accounts',
            ['used_at' => '2026-09-01 12:00:00+00'],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $id],
        );

        $this->requestUpdate($id, [...$account, 'kind' => 'CURRENT']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/v1/accounts');
        $used = $this->items()[0];
        self::assertTrue($used['used']);
        self::assertFalse($used['kindEditable']);
        self::assertSame('USED', $used['kindEditReason']);
    }

    public function testMutationsRequireCsrfAndAuditWithoutFinancialLabels(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->requestCreate($this->payload('Secret household account'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->ownAccountCount());

        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $account = $this->createAccount(label: 'Secret household account', overrides: ['maskedIdentifier' => '4821']);
        $id = self::stringValue($account, 'id');

        $this->requestUpdate($id, [...$account, 'closedOn' => '2026-02-01']);
        self::assertResponseIsSuccessful();
        $this->requestArchive($id, self::intValue($this->decode(), 'version'));
        self::assertResponseIsSuccessful();

        $events = $this->connection->fetchAllAssociative(
            "SELECT event_type, entity_type, entity_id, actor_id, after_json FROM audit_events
             WHERE workspace_id = ? AND event_type LIKE 'account.%' ORDER BY occurred_at, event_type",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertSame(
            ['account.created', 'account.updated', 'account.closed', 'account.archived'],
            array_column($events, 'event_type'),
        );
        foreach ($events as $event) {
            self::assertSame('financial_account', $event['entity_type']);
            self::assertSame($id, $event['entity_id']);
            self::assertSame(WorkspaceFixture::OWNER_ID, $event['actor_id']);
            self::assertIsString($event['after_json']);
            $before = $event['before_json'];
            self::assertTrue(null === $before || is_string($before));
            // Both sides are asserted: a one-sided redaction change must fail here.
            $sides = $event['after_json'].(string) $before;
            self::assertStringNotContainsString('Secret household account', $sides);
            self::assertStringNotContainsString('4821', $sides);
        }
    }

    public function testQueryBoundsAndPayloadSizeAreEnforced(): void
    {
        $this->client->request('GET', '/api/v1/accounts?perPage=0');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts?perPage=101');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts?page=1001');
        self::assertResponseStatusCodeSame(400);

        // Five digits never reach the use case: the controller bounds the shape first.
        $this->client->request('GET', '/api/v1/accounts?page=10000');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts?kind=UNKNOWN');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts?includeArchived=maybe');
        self::assertResponseStatusCodeSame(400);

        $this->requestCreate($this->payload(str_repeat('x', 17_000)));
        self::assertResponseStatusCodeSame(413);

        $this->client->request('POST', '/api/v1/accounts', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}');
        self::assertResponseStatusCodeSame(415);

        $this->createAccount(label: str_repeat('é', 80));
        $this->requestCreate($this->payload(str_repeat('a', 81)));
        self::assertResponseStatusCodeSame(422);

        // A JSON string is not an integer: the version is asserted, never coerced.
        $account = $this->createAccount(label: 'Compte courant');
        $body = $this->updateBody($account);
        $body['version'] = (string) self::intValue($account, 'version');
        $this->client->request(
            'PUT',
            '/api/v1/accounts/'.self::stringValue($account, 'id'),
            server: self::jsonHeaders(),
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createAccount(string $label, array $overrides = []): array
    {
        $this->requestCreate($this->payload($label, $overrides));
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function requestCreate(array $body): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts',
            server: self::jsonHeaders(),
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $account */
    private function requestUpdate(string $id, array $account): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/accounts/'.$id,
            server: self::jsonHeaders(),
            content: json_encode($this->updateBody($account), JSON_THROW_ON_ERROR),
        );
    }

    private function requestArchive(string $id, int $version): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts/'.$id.'/archive',
            server: self::jsonHeaders(),
            content: json_encode(['version' => $version], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function updateBody(array $account): array
    {
        return [
            'label' => $account['label'],
            'kind' => $account['kind'],
            'maskedIdentifier' => $account['maskedIdentifier'],
            'valuationMode' => $account['valuationMode'],
            'liquidityLevel' => $account['liquidityLevel'],
            'includeInNetWorth' => $account['includeInNetWorth'],
            'includeInEmergencyFund' => $account['includeInEmergencyFund'],
            'openedOn' => $account['openedOn'],
            'closedOn' => $account['closedOn'],
            'version' => $account['version'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(string $label, array $overrides = []): array
    {
        return [
            'label' => $label,
            'assetCode' => 'EUR',
            'kind' => 'SAVINGS',
            'maskedIdentifier' => null,
            'valuationMode' => 'TRANSACTIONS',
            'liquidityLevel' => 'IMMEDIATE',
            'includeInNetWorth' => true,
            'includeInEmergencyFund' => false,
            'openedOn' => '2026-01-10',
            'closedOn' => null,
            ...$overrides,
        ];
    }

    private function ownAccountCount(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM account_financial_accounts WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function insertAccount(string $id, string $workspaceId, string $label): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'label' => $label,
            'asset_code' => 'EUR',
            'kind' => 'SAVINGS',
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
        self::assertIsArray($decoded);

        $typed = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $typed[$key] = $value;
        }

        return $typed;
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        $items = $this->decode()['items'] ?? null;
        self::assertIsList($items);

        return array_map(static function (mixed $item): array {
            self::assertIsArray($item);

            $typed = [];
            foreach ($item as $key => $value) {
                self::assertIsString($key);
                $typed[$key] = $value;
            }

            return $typed;
        }, $items);
    }

    /** @param array<string, mixed> $data */
    private static function stringValue(array $data, string $key): string
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsString($data[$key]);

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function intValue(array $data, string $key): int
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsInt($data[$key]);

        return $data[$key];
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
