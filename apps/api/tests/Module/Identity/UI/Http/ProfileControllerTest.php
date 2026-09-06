<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The owner self-service endpoints end to end: display name, password change,
 * their refusals, and the session guarantees a credential change must hold.
 */
final class ProfileControllerTest extends WebTestCase
{
    private const string CREATED_AT = '2026-08-30 12:00:00.000000+00';
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';
    private const string EMAIL = 'owner@example.test';
    private const string PASSWORD = 'correct horse battery staple';
    private const string NEW_PASSWORD = 'a different long passphrase';

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
        $this->clearIdentityData();
        $this->resetRateLimiters();

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->clearIdentityData();
        }

        parent::tearDown();
    }

    public function testAnAnonymousCallerCannotReadOrEditTheProfile(): void
    {
        $this->provisionOwner();

        $this->client->request('GET', '/api/v1/profile');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('PATCH', '/api/v1/profile', server: self::jsonHeaders(), content: (string) json_encode(['displayName' => 'Intrus']));
        self::assertResponseStatusCodeSame(401);

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]));
        self::assertResponseStatusCodeSame(401);

        self::assertSame('Owner', $this->storedDisplayName());
    }

    public function testTheProfileIsReadableBeforeAnyBusinessDataExists(): void
    {
        // No account, balance or transaction row is created here: the settings
        // screen must render on a freshly provisioned install.
        $this->provisionOwner();
        $this->signIn();

        $this->client->request('GET', '/api/v1/profile');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('cache-control'));
        self::assertSame(
            ['id' => self::USER_ID, 'email' => self::EMAIL, 'displayName' => 'Owner'],
            $this->decode(),
        );
    }

    public function testTheOwnerEditsTheirDisplayName(): void
    {
        $this->provisionOwner();
        $this->signIn();

        $this->client->request('PATCH', '/api/v1/profile', server: self::jsonHeaders(), content: (string) json_encode(['displayName' => '  Marie Dupont  ']));

        self::assertResponseIsSuccessful();
        self::assertSame('Marie Dupont', $this->decode()['displayName']);
        self::assertSame('Marie Dupont', $this->storedDisplayName());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedDisplayNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \t "];
        yield 'over long' => [str_repeat('a', 101)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedDisplayNames')]
    public function testAnInvalidDisplayNameIsRefusedWithAFieldLevelProblem(string $displayName): void
    {
        $this->provisionOwner();
        $this->signIn();

        $this->client->request('PATCH', '/api/v1/profile', server: self::jsonHeaders(), content: (string) json_encode(['displayName' => $displayName]));

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('/problems/invalid-display-name', $this->decode()['type']);
        self::assertSame('Owner', $this->storedDisplayName());
    }

    public function testAProfileBodyCarryingAnyOtherFieldIsRefused(): void
    {
        $this->provisionOwner();
        $this->signIn();

        $this->client->request('PATCH', '/api/v1/profile', server: self::jsonHeaders(), content: (string) json_encode([
            'displayName' => 'Marie Dupont',
            'email' => 'someone-else@example.test',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(self::EMAIL, $this->storedEmail());
        self::assertSame('Owner', $this->storedDisplayName());
    }

    public function testAPasswordChangeKeepsTheCallerSignedInOnARotatedSession(): void
    {
        $this->provisionOwner();
        $this->signIn();
        $before = $this->sessionCookieValue();

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(204);
        // Session fixation: the identifier the change was made on must not
        // survive the credential it was bound to.
        self::assertNotSame($before, $this->sessionCookieValue());

        $this->client->request('GET', '/api/v1/session');
        self::assertTrue($this->decode()['authenticated']);
    }

    public function testThePasswordThatWasReplacedNoLongerOpensASession(): void
    {
        $this->provisionOwner();
        $this->signIn();

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', '/api/v1/session');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::NEW_PASSWORD]));
        self::assertResponseStatusCodeSame(204);
    }

    public function testAWrongCurrentPasswordIsRefusedAndChangesNothing(): void
    {
        $this->provisionOwner();
        $this->signIn();
        $storedHash = $this->storedPasswordHash();

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => 'not the current password',
            'newPassword' => self::NEW_PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid-current-password', $this->decode()['type']);
        self::assertSame($storedHash, $this->storedPasswordHash());
    }

    public function testANewPasswordBreakingThePolicyIsRefused(): void
    {
        $this->provisionOwner();
        $this->signIn();
        $storedHash = $this->storedPasswordHash();

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => 'short',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/weak-password', $this->decode()['type']);
        self::assertSame($storedHash, $this->storedPasswordHash());
    }

    public function testANewPasswordEqualToTheCurrentOneIsRefused(): void
    {
        $this->provisionOwner();
        $this->signIn();
        $storedHash = $this->storedPasswordHash();

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/reused-password', $this->decode()['type']);
        self::assertSame($storedHash, $this->storedPasswordHash());
    }

    public function testTheCurrentPasswordCheckIsThrottledOnItsOwnBudget(): void
    {
        $this->provisionOwner();
        $this->signIn();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
                'currentPassword' => 'not the current password',
                'newPassword' => self::NEW_PASSWORD,
            ]));
            self::assertResponseStatusCodeSame(422);
        }

        // The sixth attempt carries the correct password and is still refused,
        // so throttling cannot be used to confirm a guess.
        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $retryAfter = (int) $this->client->getResponse()->headers->get('retry-after');
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(960, $retryAfter);

        // Signing in still works: the two budgets are independent.
        $this->client->request('DELETE', '/api/v1/session');
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));
        self::assertResponseStatusCodeSame(204);
    }

    public function testARefusalThatIsNotAGuessNeverSpendsTheThrottleBudget(): void
    {
        $this->provisionOwner();
        $this->signIn();

        // A password manager filling all three fields with the stored value, and
        // a candidate of the wrong length, are malformed requests rather than
        // attempts at the current password. Five of each must not lock the owner
        // out of a change they are entitled to make.
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
                'currentPassword' => self::PASSWORD,
                'newPassword' => self::PASSWORD,
            ]));
            self::assertResponseStatusCodeSame(422);

            $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
                'currentPassword' => self::PASSWORD,
                'newPassword' => 'short',
            ]));
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(204);
    }

    public function testAWeakCandidateCannotRefillABudgetSpentOnWrongGuesses(): void
    {
        $this->provisionOwner();
        $this->signIn();

        // The policy check runs before the limiter, so it must not clear what
        // earlier wrong guesses have already spent.
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
                'currentPassword' => 'not the current password',
                'newPassword' => self::NEW_PASSWORD,
            ]));
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => 'short',
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => 'not the current password',
            'newPassword' => self::NEW_PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(429);
    }

    public function testAFailedCurrentPasswordCheckIsRecordedInTheTrail(): void
    {
        $this->provisionOwner();
        $this->signIn();

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => 'not the current password',
            'newPassword' => self::NEW_PASSWORD,
        ]));
        self::assertResponseStatusCodeSame(422);

        $events = $this->connection->fetchAllAssociative(
            'SELECT event_type, actor_id, before_json::text AS before_json, after_json::text AS after_json FROM audit_events WHERE workspace_id = ?',
            [self::WORKSPACE_ID],
        );
        $failures = array_values(array_filter(
            $events,
            static fn (array $row): bool => 'user.password_change_failed' === $row['event_type'],
        ));

        self::assertCount(1, $failures);
        self::assertSame(self::USER_ID, $failures[0]['actor_id']);
        self::assertStringNotContainsString('not the current password', (string) json_encode($events));
    }

    public function testAMutationWithoutACsrfTokenIsRejected(): void
    {
        $this->provisionOwner();
        $this->signIn();

        $this->client->request(
            'PUT',
            '/api/v1/profile/password',
            server: self::jsonHeaders() + ['HTTP_X_CSRF_TOKEN' => ''],
            content: (string) json_encode(['currentPassword' => self::PASSWORD, 'newPassword' => self::NEW_PASSWORD]),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame('/problems/csrf-token', $this->decode()['type']);
    }

    public function testTheAuditTrailRecordsTheChangeWithoutEitherPassword(): void
    {
        $this->provisionOwner();
        $this->signIn();

        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]));
        self::assertResponseStatusCodeSame(204);

        $events = $this->connection->fetchAllAssociative(
            'SELECT event_type, actor_id, before_json::text AS before_json, after_json::text AS after_json FROM audit_events WHERE workspace_id = ?',
            [self::WORKSPACE_ID],
        );
        $changes = array_values(array_filter($events, static fn (array $row): bool => 'user.password_changed' === $row['event_type']));

        self::assertCount(1, $changes);
        self::assertSame(self::USER_ID, $changes[0]['actor_id']);
        $encoded = (string) json_encode($events);
        self::assertStringNotContainsString(self::PASSWORD, $encoded);
        self::assertStringNotContainsString(self::NEW_PASSWORD, $encoded);
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));
        self::assertResponseStatusCodeSame(204);
    }

    private function sessionCookieValue(): string
    {
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            if (SignedCsrfToken::COOKIE_NAME !== $cookie->getName()) {
                return $cookie->getValue();
            }
        }

        self::fail('The client holds no session cookie.');
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

    private function storedDisplayName(): string
    {
        return $this->ownerColumn('display_name');
    }

    private function storedEmail(): string
    {
        return $this->ownerColumn('email');
    }

    private function storedPasswordHash(): string
    {
        return $this->ownerColumn('password_hash');
    }

    private function ownerColumn(string $column): string
    {
        $value = $this->connection->fetchOne(sprintf('SELECT %s FROM identity_users WHERE id = ?', $column), [self::USER_ID]);
        self::assertIsString($value);

        return $value;
    }

    private function resetRateLimiters(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    private function provisionOwner(): void
    {
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);

        $this->connection->insert('identity_users', [
            'id' => self::USER_ID,
            'email' => self::EMAIL,
            'display_name' => 'Owner',
            'created_at' => self::CREATED_AT,
            'password_hash' => $hasher->hash(PlainPassword::fromString(self::PASSWORD)),
            'disabled_at' => null,
        ]);
        $this->connection->insert('identity_workspaces', [
            'id' => self::WORKSPACE_ID,
            'name' => 'Household',
            'timezone' => 'Europe/Paris',
            'base_currency' => 'EUR',
            'created_at' => self::CREATED_AT,
        ]);
        $this->connection->insert('identity_workspace_memberships', [
            'id' => '00000000-0000-7000-8000-0000000000b1',
            'workspace_id' => self::WORKSPACE_ID,
            'user_id' => self::USER_ID,
            'role' => 'OWNER',
            'created_at' => self::CREATED_AT,
        ]);
    }

    private function clearIdentityData(): void
    {
        $this->connection->executeStatement('DELETE FROM sessions');
        $this->connection->executeStatement('TRUNCATE TABLE audit_events');
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}
