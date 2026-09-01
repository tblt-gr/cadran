<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
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
    private const string USER_ID = WorkspaceFixture::OWNER_ID;
    private const string OTHER_WORKSPACE_ID = WorkspaceFixture::OTHER_WORKSPACE;
    private const string EMAIL = WorkspaceFixture::OWNER_EMAIL;
    private const string PASSWORD = WorkspaceFixture::OWNER_PASSWORD;
    private const string FOREIGN_EVENT_ID = '00000000-0000-7000-8000-0000000000f1';

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
        $this->seedOwner();

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

    public function testAnEventOfTheOwnWorkspaceIsReadableByItsIdentifier(): void
    {
        $this->signIn();
        $this->client->request('GET', '/api/v1/audit-events');
        $listed = $this->items()[0];

        $this->client->request('GET', '/api/v1/audit-events/'.self::identifierOf($listed));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('cache-control'));
        self::assertSame($listed, $this->decode());
    }

    public function testAForeignIdentifierIsAnsweredExactlyLikeAnUnknownOne(): void
    {
        $this->insertForeignEvent();
        $this->signIn();

        $this->client->request('GET', '/api/v1/audit-events/'.self::FOREIGN_EVENT_ID);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $foreign = (string) $this->client->getResponse()->getContent();

        $this->client->request('GET', '/api/v1/audit-events/00000000-0000-7000-8000-0000000000ee');
        self::assertResponseStatusCodeSame(404);

        // Byte-identical: probing identifiers must not reveal which ones exist
        // in another workspace.
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString(self::OTHER_WORKSPACE_ID, $foreign);
    }

    public function testAMalformedIdentifierIsRefusedWithoutReachingTheDatabase(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/v1/audit-events/not-a-uuid');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAnAnonymousCallerCannotReadAnEventByIdentifier(): void
    {
        $this->client->request('GET', '/api/v1/audit-events/'.self::FOREIGN_EVENT_ID);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testReadingByIdentifierAlsoRequiresAMembership(): void
    {
        $this->signIn();
        $this->client->request('GET', '/api/v1/audit-events');
        $listed = $this->items()[0];
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships WHERE user_id = ?', [self::USER_ID]);

        $this->client->request('GET', '/api/v1/audit-events/'.self::identifierOf($listed));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The reciprocal of every other isolation test here: the second workspace's
     * owner signs in for real and sees its own trail and nothing of the first.
     * Without this side, a fixture that seeded everything into one workspace,
     * or a session that resolved the wrong membership, would still look green.
     */
    public function testTheOtherWorkspaceOwnerSeesItsOwnTrailAndNothingOfTheFirst(): void
    {
        $this->insertForeignEvent();
        $this->signIn();
        $this->client->request('DELETE', '/api/v1/session');
        self::assertResponseStatusCodeSame(204);

        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $this->client->request('GET', '/api/v1/audit-events');

        self::assertResponseIsSuccessful();
        $items = $this->items();
        self::assertSame(
            ['session.opened', 'workspace.created'],
            array_column($items, 'eventType'),
        );
        self::assertSame(self::FOREIGN_EVENT_ID, $items[1]['id']);
        // The first owner signed in and out just above; none of it is visible.
        self::assertStringNotContainsString(self::USER_ID, (string) $this->client->getResponse()->getContent());
    }

    public function testTheOtherWorkspaceOwnerCannotReadAnEventOfTheFirstWorkspace(): void
    {
        $this->signIn();
        $this->client->request('GET', '/api/v1/audit-events');
        $ownEventId = self::identifierOf($this->items()[0]);
        $this->client->request('DELETE', '/api/v1/session');

        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $this->client->request('GET', '/api/v1/audit-events/'.$ownEventId);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    /**
     * @param array<mixed> $item
     */
    private static function identifierOf(array $item): string
    {
        $id = $item['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function signIn(string $email = self::EMAIL): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode([
            'email' => $email,
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

        $this->fixture->seed($hasher);
    }

    private function insertForeignEvent(): void
    {
        $this->connection->insert('audit_events', [
            'id' => self::FOREIGN_EVENT_ID,
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
}
