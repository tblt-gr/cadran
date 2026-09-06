<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Revocation against the session handler the application actually ships.
 *
 * The rest of the suite runs on mock_file storage, which never reaches a save
 * handler: the `sessions` table stays empty there, so a revocation assertion
 * would pass whatever the DELETE matched. This case boots the `sessiondb`
 * environment, where native storage drives PdoSessionHandler, so the rows the
 * handler writes are the rows the revocation is judged on.
 *
 * One browser is simulated by swapping cookie jars on a single client: PHP
 * holds one native session per process, so two live clients would fight over it.
 */
final class SessionRevocationTest extends WebTestCase
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

        $this->client = self::createClient(['environment' => 'sessiondb']);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->databaseReady = true;
        $this->clearIdentityData();
        $this->provisionOwner();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->clearIdentityData();
        }

        parent::tearDown();
    }

    public function testAPasswordChangeDropsEveryOtherStoredSessionAndKeepsTheCallersOwn(): void
    {
        $first = $this->signIn();
        $second = $this->signIn();

        // Both browsers really have a server-side record; without this the rest
        // of the case would assert nothing.
        self::assertSame(2, $this->storedSessionCount());
        self::assertNotSame($first, $second);

        $this->useSession($first);
        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]));
        self::assertResponseStatusCodeSame(204);

        // Exactly one record survives: the rotated one the acting browser now
        // carries. The identifier it was changed on is gone with the others.
        $rotated = $this->currentSessionCookie();
        self::assertNotSame($first, $rotated);
        self::assertSame([$rotated], $this->storedSessionIds());

        $this->client->request('GET', '/api/v1/session');
        self::assertTrue($this->decode()['authenticated'], 'The browser that changed the password stays signed in.');

        // The second browser still holds a perfectly well-formed cookie; it just
        // no longer names anything.
        $this->useSession($second);
        $this->client->request('GET', '/api/v1/session');
        self::assertFalse($this->decode()['authenticated']);
    }

    public function testARefusedChangeRevokesNothing(): void
    {
        $first = $this->signIn();
        $second = $this->signIn();

        $this->useSession($first);
        $this->client->request('PUT', '/api/v1/profile/password', server: self::jsonHeaders(), content: (string) json_encode([
            'currentPassword' => 'not the current password',
            'newPassword' => self::NEW_PASSWORD,
        ]));
        self::assertResponseStatusCodeSame(422);

        self::assertSame(2, $this->storedSessionCount());
        $this->useSession($second);
        $this->client->request('GET', '/api/v1/session');
        self::assertTrue($this->decode()['authenticated']);
    }

    /**
     * Signs in on a fresh jar and returns the session identifier it received.
     */
    private function signIn(): string
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
        self::assertResponseStatusCodeSame(204);

        return $this->currentSessionCookie();
    }

    private function useSession(string $sessionId): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        $jar->set(new Cookie(self::sessionCookieName(), $sessionId));

        // A safe probe replants the CSRF cookie for this jar.
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->client->request('GET', '/api/v1/session');
        $csrf = $jar->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    private function currentSessionCookie(): string
    {
        $cookie = $this->client->getCookieJar()->get(self::sessionCookieName());
        self::assertNotNull($cookie, 'The client holds no session cookie.');

        return $cookie->getValue();
    }

    private static function sessionCookieName(): string
    {
        return 'cadran_session';
    }

    private function storedSessionCount(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM sessions');
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    /** @return list<string> */
    private function storedSessionIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT sess_id FROM sessions ORDER BY sess_id');

        return $ids;
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
