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
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

final class SessionControllerTest extends WebTestCase
{
    private const string CREATED_AT = '2026-08-30 12:00:00.000000+00';
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';
    private const string EMAIL = 'owner@example.test';
    private const string PASSWORD = 'correct horse battery staple';

    private KernelBrowser $client;
    private Connection $connection;
    private bool $databaseReady = false;
    private string $csrfToken = '';

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
        $this->resetLoginThrottling();

        // A safe probe plants the double-submit CSRF cookie; echo it back on
        // every following request the way the SPA does. Individual tests
        // override HTTP_X_CSRF_TOKEN to exercise the rejection paths.
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->csrfToken = $csrf->getValue();
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $this->csrfToken);
    }

    /**
     * The login rate limiter uses a persistent cache pool, so its counters would
     * otherwise carry across tests and across suite runs.
     */
    private function resetLoginThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->clearIdentityData();
        }

        parent::tearDown();
    }

    public function testAFreshInstallReportsThatPasswordSetupIsRequired(): void
    {
        $this->provisionOwner(plainPassword: null);

        $this->client->request('GET', '/api/v1/session');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('cache-control'));
        self::assertSame(
            [
                'provisioned' => true,
                'authenticated' => false,
                'setupRequired' => true,
                'user' => null,
                'workspace' => null,
            ],
            $this->decode(),
        );
    }

    public function testAnAnonymousProbeBeforeProvisioningReportsNotProvisioned(): void
    {
        $this->client->request('GET', '/api/v1/session');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'provisioned' => false,
                'authenticated' => false,
                'setupRequired' => false,
                'user' => null,
                'workspace' => null,
            ],
            $this->decode(),
        );
    }

    public function testTheOwnerDefinesTheirInitialPasswordExactlyOnce(): void
    {
        $this->provisionOwner(plainPassword: null);

        $this->client->request('PUT', '/api/v1/session/password', server: self::jsonHeaders(), content: (string) json_encode(['password' => self::PASSWORD]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/session');
        self::assertSame(false, $this->decode()['setupRequired']);

        $this->client->request('PUT', '/api/v1/session/password', server: self::jsonHeaders(), content: (string) json_encode(['password' => 'another strong password']));
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testItRejectsAPasswordShorterThanThePolicy(): void
    {
        $this->provisionOwner(plainPassword: null);

        $this->client->request('PUT', '/api/v1/session/password', server: self::jsonHeaders(), content: (string) json_encode(['password' => 'short']));

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame(422, $this->decode()['status']);
    }

    public function testItRejectsAMalformedPasswordPayload(): void
    {
        $this->provisionOwner(plainPassword: null);

        $this->client->request('PUT', '/api/v1/session/password', server: self::jsonHeaders(), content: '{"password":');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('Requête invalide', $this->decode()['title']);
    }

    public function testANonJsonLoginPostIsRejectedAsUnsupportedMediaType(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);

        $this->client->request(
            'POST',
            '/api/v1/session',
            server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            content: 'email=owner@example.test&password=whatever',
        );

        self::assertResponseStatusCodeSame(415);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAMalformedJsonLoginPostReturnsACoherentClientError(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: '{"email":');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $body = $this->decode();
        self::assertSame(400, $body['status']);
        self::assertSame('Requête invalide', $body['title']);
    }

    public function testAValidLoginOpensASessionThatExposesTheUserAndWorkspace(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));

        self::assertResponseStatusCodeSame(204);
        // The cookie name is "cadran_session" in production; the test session
        // storage overrides it, so the hardening flags are what matter here.
        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());

        $this->client->request('GET', '/api/v1/session');
        self::assertSame(
            [
                'provisioned' => true,
                'authenticated' => true,
                'setupRequired' => false,
                'user' => ['id' => self::USER_ID, 'email' => self::EMAIL, 'displayName' => 'Owner'],
                'workspace' => ['id' => self::WORKSPACE_ID, 'role' => 'OWNER'],
            ],
            $this->decode(),
        );
    }

    public function testAWrongPasswordAndAnUnknownEmailAreIndistinguishable(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => 'wrong password value']));
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $wrongPassword = $this->decode();

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => 'nobody@example.test', 'password' => 'wrong password value']));
        self::assertResponseStatusCodeSame(401);
        $unknownEmail = $this->decode();

        self::assertSame($wrongPassword, $unknownEmail);
    }

    public function testADisabledAccountIsRejectedAfterThePasswordCheck(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD, disabled: true);

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame(403, $this->decode()['status']);
    }

    public function testLoggingOutClearsTheSession(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', '/api/v1/session');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/session');
        self::assertFalse($this->decode()['authenticated']);
    }

    public function testLoginDoesNotHonourAClientChosenSessionId(): void
    {
        // Session fixation: an attacker plants a known id and waits for the
        // victim to authenticate on it. Login must issue its own id instead.
        $this->provisionOwner(plainPassword: self::PASSWORD);
        $factory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $this->client->getCookieJar()->set(new Cookie($factory->createSession()->getName(), 'attacker-chosen-session-id'));

        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));

        self::assertResponseStatusCodeSame(204);
        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie);
        self::assertNotSame('attacker-chosen-session-id', $cookie->getValue());
    }

    public function testAnUnknownSessionCookieIsTreatedAsAnonymous(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);
        $factory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $this->client->getCookieJar()->set(new Cookie($factory->createSession()->getName(), 'never-issued-by-this-server'));

        $this->client->request('GET', '/api/v1/session');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->decode()['authenticated']);
    }

    public function testAMidSessionDisableDropsBackToAnonymous(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));
        self::assertResponseStatusCodeSame(204);

        $this->connection->executeStatement(
            'UPDATE identity_users SET disabled_at = ? WHERE id = ?',
            ['2026-08-30 13:00:00+00', self::USER_ID],
        );

        // refreshUser drops the firewall token on the disable, and DescribeSession
        // re-reads the row: the probe reports anonymous either way.
        $this->client->request('GET', '/api/v1/session');
        self::assertFalse($this->decode()['authenticated']);
    }

    public function testTheFoundationStatusEndpointStaysPublicBehindTheFirewall(): void
    {
        $this->client->request('GET', '/api/v1/status');

        self::assertResponseIsSuccessful();
    }

    public function testAMutationWithoutACsrfTokenIsRejected(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);

        $this->client->request(
            'POST',
            '/api/v1/session',
            server: self::jsonHeaders() + ['HTTP_X_CSRF_TOKEN' => ''],
            content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame(403, $this->decode()['status']);
    }

    public function testAMutationWithAMalformedCsrfTokenIsRejected(): void
    {
        $this->provisionOwner(plainPassword: null);

        $this->client->request(
            'PUT',
            '/api/v1/session/password',
            server: self::jsonHeaders() + ['HTTP_X_CSRF_TOKEN' => 'not.a.token'],
            content: (string) json_encode(['password' => self::PASSWORD]),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAValidTokenThatDoesNotMatchTheCookieIsRejected(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);
        $minter = self::getContainer()->get(SignedCsrfToken::class);
        self::assertInstanceOf(SignedCsrfToken::class, $minter);
        $foreignButValid = $minter->issue();

        $this->client->request(
            'DELETE',
            '/api/v1/session',
            server: ['HTTP_X_CSRF_TOKEN' => $foreignButValid],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAMutationFromAForeignOriginIsRejectedEvenWithAValidToken(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);

        $this->client->request(
            'POST',
            '/api/v1/session',
            server: self::jsonHeaders() + ['HTTP_ORIGIN' => 'https://evil.example'],
            content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testTheSixthFailedLoginIsThrottledWithoutRevealingTheAccount(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => 'wrong password value']));
            self::assertResponseStatusCodeSame(401);
        }

        // The sixth attempt sends the *correct* password: it is still refused,
        // so a caller cannot use throttling to confirm a guess.
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        // Retry-After tracks the limiter's own recovery estimate: positive and
        // never past one interval plus a minute of rounding.
        $retryAfter = (int) $this->client->getResponse()->headers->get('retry-after');
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(960, $retryAfter);
        $body = $this->decode();
        self::assertSame(429, $body['status']);
        self::assertStringNotContainsStringIgnoringCase(self::EMAIL, (string) json_encode($body));
    }

    public function testAMutationDeclaringACrossSiteFetchIsRejectedWithACsrfProblem(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);

        $this->client->request(
            'POST',
            '/api/v1/session',
            server: self::jsonHeaders() + ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
            content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        // A distinct type lets the SPA tell a stale token from a disabled account.
        self::assertSame('/problems/csrf-token', $this->decode()['type']);
    }

    public function testTheCsrfCookieIsReadableAndLockedToTheSite(): void
    {
        $cookie = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);

        self::assertNotNull($cookie);
        self::assertFalse($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
    }

    public function testAnUnknownEmailIsThrottledOnTheSameCurveAsAKnownOne(): void
    {
        $this->provisionOwner(plainPassword: self::PASSWORD);

        $statuses = [];
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: (string) json_encode(['email' => 'ghost@example.test', 'password' => 'whatever value here']));
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        // Five 401s then a 429 — identical to the known-account curve above, so
        // the throttle leaks nothing about which emails exist.
        self::assertSame([401, 401, 401, 401, 401, 429], $statuses);
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

    private function provisionOwner(?string $plainPassword, bool $disabled = false): void
    {
        $hash = null;
        if (null !== $plainPassword) {
            $hasher = self::getContainer()->get(PasswordHasher::class);
            self::assertInstanceOf(PasswordHasher::class, $hasher);
            $hash = $hasher->hash(PlainPassword::fromString($plainPassword));
        }

        $this->connection->insert('identity_users', [
            'id' => self::USER_ID,
            'email' => self::EMAIL,
            'display_name' => 'Owner',
            'created_at' => self::CREATED_AT,
            'password_hash' => $hash,
            'disabled_at' => $disabled ? '2026-08-30 08:00:00+00' : null,
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
        // TRUNCATE, not DELETE: the append-only trigger on audit_events rejects
        // row deletion, and the workspace and actor foreign keys make the trail
        // block the identity cleanup that follows.
        $this->connection->executeStatement('TRUNCATE TABLE audit_events');
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}
