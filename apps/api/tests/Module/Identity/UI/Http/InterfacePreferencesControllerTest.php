<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InterfacePreferencesControllerTest extends WebTestCase
{
    private const string URI = '/api/v1/interface-preferences';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($this->connection);
        $this->fixture->reset();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
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
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testAWorkspaceWithoutAStoredRowReadsTheDefaultsAtVersionZero(): void
    {
        $this->signIn();

        self::assertSame(['includeUpcoming' => true, 'version' => 0], $this->read());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAFirstSaveInsertsTheRowAndTheNextReadReturnsIt(): void
    {
        $this->signIn();

        $saved = $this->save(false, 0);

        self::assertSame(['includeUpcoming' => false, 'version' => 1], $saved);
        self::assertSame($saved, $this->read());
    }

    public function testEachSaveIncrementsTheVersionAndIsAudited(): void
    {
        $this->signIn();
        $this->save(false, 0);
        $second = $this->save(true, 1);

        self::assertSame(['includeUpcoming' => true, 'version' => 2], $second);
        $events = $this->connection->fetchFirstColumn(
            'SELECT after_json::text FROM audit_events WHERE event_type = ? ORDER BY occurred_at, id',
            ['workspace.interface_preferences_updated'],
        );
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertIsString($event);
            self::assertStringNotContainsString('EUR', $event);
        }
    }

    public function testTwoEditsFromTheSameReadLeaveOnlyTheFirstOneApplied(): void
    {
        $this->signIn();
        $this->save(true, 0);

        $this->put(false, 1);
        self::assertResponseStatusCodeSame(200);

        $this->put(true, 1);
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame(['includeUpcoming' => false, 'version' => 2], $this->read());
    }

    public function testASaveAtVersionZeroOnAnExistingRowIsStale(): void
    {
        $this->signIn();
        $this->save(false, 0);

        $this->put(true, 0);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['includeUpcoming' => false, 'version' => 1], $this->read());
    }

    public function testMalformedBodiesAreRefused(): void
    {
        $this->signIn();
        foreach ([
            ['includeUpcoming' => 'yes', 'version' => 0],
            ['includeUpcoming' => true, 'version' => '0'],
            ['includeUpcoming' => true],
            ['includeUpcoming' => true, 'version' => 0, 'workspaceId' => WorkspaceFixture::OTHER_WORKSPACE],
            ['includeUpcoming' => true, 'version' => -1],
        ] as $body) {
            $this->client->request('PUT', self::URI, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame(0, $this->read()['version']);
    }

    public function testANonJsonBodyIsRefusedWith415(): void
    {
        $this->signIn();

        $this->client->request('PUT', self::URI, server: ['CONTENT_TYPE' => 'text/plain'], content: 'includeUpcoming=false');

        self::assertResponseStatusCodeSame(415);
    }

    public function testOneWorkspaceNeverReadsOrOverwritesThePreferencesOfAnother(): void
    {
        $this->signIn();
        $this->save(false, 0);

        $this->client->request('DELETE', '/api/v1/session');
        self::assertResponseStatusCodeSame(204);
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);

        self::assertSame(['includeUpcoming' => true, 'version' => 0], $this->read());
        $this->save(true, 0);

        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn();
        self::assertSame(['includeUpcoming' => false, 'version' => 1], $this->read());
        self::assertSame(2, $this->rows());
    }

    public function testASaveWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->signIn();
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'forged');

        $this->put(false, 0);

        self::assertResponseStatusCodeSame(403);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $this->freshToken());
        self::assertSame(0, $this->read()['version']);
    }

    public function testAnAnonymousCallerReadsAndWritesNothing(): void
    {
        $this->client->request('GET', self::URI);
        self::assertResponseStatusCodeSame(401);
        $this->put(false, 0);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->rows());
    }

    private function rows(): int
    {
        $value = $this->connection->fetchOne('SELECT count(*) FROM identity_interface_preferences');
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function freshToken(): string
    {
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);

        return $csrf->getValue();
    }

    private function signIn(string $email = WorkspaceFixture::OWNER_EMAIL): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $this->client->request('GET', self::URI);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function save(bool $includeUpcoming, int $version): array
    {
        $this->put($includeUpcoming, $version);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    private function put(bool $includeUpcoming, int $version): void
    {
        $this->client->request('PUT', self::URI, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'includeUpcoming' => $includeUpcoming,
            'version' => $version,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $fields = [];
        foreach ($data as $key => $value) {
            self::assertIsString($key);
            $fields[$key] = $value;
        }

        return $fields;
    }
}
