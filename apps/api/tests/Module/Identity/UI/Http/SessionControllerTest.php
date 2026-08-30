<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Http;

use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;
use Doctrine\DBAL\Connection;
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
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}
