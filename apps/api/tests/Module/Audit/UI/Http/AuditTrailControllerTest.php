<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The HTTP surface of AUD-001: what an authenticated owner reads, and what an
 * anonymous caller or another workspace's data never reaches.
 */
final class AuditTrailControllerTest extends WebTestCase
{
    private const string CREATED_AT = '2026-08-31 12:00:00.000000+00';
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';
    private const string OTHER_WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a2';
    private const string EMAIL = 'owner@example.test';
    private const string PASSWORD = 'correct horse battery staple';

    private KernelBrowser $client;
    private Connection $connection;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        if (false === getenv('DATABASE_URL')) {
            if (false !== getenv('CI')) {
                self::fail('DATABASE_URL must be set in CI; PostgreSQL integration tests may not be skipped there.');
            }

            self::markTestSkipped('This PostgreSQL integration test requires DATABASE_URL.');
        }

        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->databaseReady = true;
        $this->clearData();
        $this->resetLoginThrottling();
        $this->seedOwner();

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->clearData();
        }

        parent::tearDown();
    }

    public function testAnAnonymousCallerIsRefusedBeforeReachingTheTrail(): void
    {
        $this->client->request('GET', '/api/v1/audit-events');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testSigningInIsItselfRecordedAndReadableByTheOwner(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/v1/audit-events');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('cache-control'));
        $items = $this->items();
        self::assertSame(['session.opened'], array_column($items, 'eventType'));
        self::assertSame(self::USER_ID, $items[0]['actorId']);
        self::assertSame(self::USER_ID, $items[0]['entityId']);
        self::assertNull($items[0]['before']);
        self::assertNull($this->nextCursor());
    }

    public function testAFailedAttemptOnAKnownAccountIsRecordedWithoutTheSubmittedCredential(): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode([
            'email' => self::EMAIL,
            'password' => 'wrong password value',
        ]));
        self::assertResponseStatusCodeSame(401);

        $this->signIn();
        $this->client->request('GET', '/api/v1/audit-events');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertSame(['session.opened', 'session.sign_in_failed'], array_column($this->items(), 'eventType'));
        self::assertStringNotContainsString('wrong password value', $body);
        self::assertStringNotContainsString(self::PASSWORD, $body);
    }

    public function testAnAttemptOnAnUnknownAccountRecordsNothing(): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode([
            'email' => 'ghost@example.test',
            'password' => 'wrong password value',
        ]));
        self::assertResponseStatusCodeSame(401);

        $this->signIn();
        $this->client->request('GET', '/api/v1/audit-events');

        self::assertSame(['session.opened'], array_column($this->items(), 'eventType'));
        self::assertStringNotContainsString('ghost@example.test', (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherWorkspaceTrailNeverAppears(): void
    {
        $this->insertForeignEvent();
        $this->signIn();

        $this->client->request('GET', '/api/v1/audit-events');

        self::assertSame(['session.opened'], array_column($this->items(), 'eventType'));
        self::assertStringNotContainsString(self::OTHER_WORKSPACE_ID, (string) $this->client->getResponse()->getContent());
    }

    public function testTheTrailPagesWithABoundedCursor(): void
    {
        $this->signIn();
        $this->client->request('DELETE', '/api/v1/session');
        self::assertResponseStatusCodeSame(204);
        $this->signIn();

        $this->client->request('GET', '/api/v1/audit-events?limit=1');

        $first = $this->items();
        self::assertCount(1, $first);
        self::assertSame('session.opened', $first[0]['eventType']);
        $cursor = $this->nextCursor();
        self::assertNotNull($cursor);

        $this->client->request('GET', '/api/v1/audit-events?limit=1&cursor='.rawurlencode($cursor));

        $second = $this->items();
        self::assertSame(['session.closed'], array_column($second, 'eventType'));
        self::assertNotSame($first[0]['id'], $second[0]['id']);
    }

    public function testAnOutOfBoundsPageSizeIsRefused(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/v1/audit-events?limit=101');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testANonNumericPageSizeIsRefused(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/v1/audit-events?limit=all');

        self::assertResponseStatusCodeSame(400);
    }

    public function testAForgedCursorIsRefusedRatherThanIgnored(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/v1/audit-events?cursor=not-a-cursor');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAnAuthenticatedUserWithoutAWorkspaceIsForbidden(): void
    {
        $this->signIn();
        // The trail keeps the events this membership produced; losing the
        // membership only costs the caller the right to read them.
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships WHERE user_id = ?', [self::USER_ID]);

        $this->client->request('GET', '/api/v1/audit-events');

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @return list<array<mixed>>
     */
    private function items(): array
    {
        $items = $this->decode()['items'] ?? null;
        self::assertIsList($items);

        $rows = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $rows[] = $item;
        }

        return $rows;
    }

    private function nextCursor(): ?string
    {
        $cursor = $this->decode()['nextCursor'] ?? null;
        self::assertTrue(null === $cursor || is_string($cursor));

        return is_string($cursor) ? $cursor : null;
    }

    /**
     * @return array<mixed>
     */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
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

    private function seedOwner(): void
    {
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);

        $this->connection->insert('identity_users', [
            'id' => self::USER_ID,
            'email' => self::EMAIL,
            'display_name' => 'Owner',
            'created_at' => self::CREATED_AT,
            'password_hash' => $hasher->hash(PlainPassword::fromString(self::PASSWORD)),
        ]);
        foreach ([self::WORKSPACE_ID, self::OTHER_WORKSPACE_ID] as $index => $workspaceId) {
            $this->connection->insert('identity_workspaces', [
                'id' => $workspaceId,
                'name' => 'Workspace '.$index,
                'timezone' => 'Europe/Paris',
                'base_currency' => 'EUR',
                'created_at' => self::CREATED_AT,
            ]);
        }
        $this->connection->insert('identity_workspace_memberships', [
            'id' => '00000000-0000-7000-8000-0000000000b1',
            'workspace_id' => self::WORKSPACE_ID,
            'user_id' => self::USER_ID,
            'role' => 'OWNER',
            'created_at' => self::CREATED_AT,
        ]);
    }

    private function insertForeignEvent(): void
    {
        $this->connection->insert('audit_events', [
            'id' => '00000000-0000-7000-8000-0000000000f1',
            'workspace_id' => self::OTHER_WORKSPACE_ID,
            'actor_id' => null,
            'event_type' => 'workspace.created',
            'entity_type' => 'workspace',
            'entity_id' => self::OTHER_WORKSPACE_ID,
            'before_json' => null,
            'after_json' => json_encode(['baseCurrency' => 'EUR'], JSON_THROW_ON_ERROR),
            'occurred_at' => self::CREATED_AT,
        ]);
    }

    private function clearData(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE audit_events');
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}
