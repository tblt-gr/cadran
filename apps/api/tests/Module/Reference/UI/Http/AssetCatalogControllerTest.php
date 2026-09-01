<?php

declare(strict_types=1);

namespace App\Tests\Module\Reference\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The HTTP surface of REF-001. The asset reference is global on purpose, so
 * the isolation question it must answer is the mirror of the usual one: every
 * workspace reads the same catalogue, and it carries nothing of its own.
 */
final class AssetCatalogControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();

        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
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

    public function testAnAnonymousCallerIsRefusedBeforeReachingTheCatalogue(): void
    {
        $this->client->request('GET', '/api/v1/assets');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testTheOwnerReadsTheReferenceWithItsPrecisionsAndCanonicalStep(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/assets');

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame(7, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(50, $body['perPage']);

        $euro = self::assetNamed($this->items(), 'EUR');
        self::assertSame([
            'code' => 'EUR',
            'kind' => 'FIAT',
            'displayName' => 'Euro',
            'storagePrecision' => 8,
            'displayPrecision' => 2,
            'roundingMode' => 'HALF_UP',
            'displayStep' => ['value' => '0.01', 'assetCode' => 'EUR'],
        ], $euro);
    }

    public function testTheCatalogueIsTheSameForEveryWorkspace(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);
        $this->client->request('GET', '/api/v1/assets');
        $own = $this->decode();

        $this->client->request('DELETE', '/api/v1/session');
        $this->resetLoginThrottling();
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $this->client->request('GET', '/api/v1/assets');

        self::assertResponseIsSuccessful();
        self::assertSame($own, $this->decode());
    }

    public function testOnePageStaysInsideItsBounds(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/assets?page=2&perPage=2');

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame(['ETH', 'EUR'], array_column($this->items(), 'code'));
        self::assertSame(2, $body['page']);
        self::assertSame(2, $body['perPage']);
    }

    public function testAnUnboundedPageIsRefused(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/assets?perPage=5000');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testANonNumericPageIsRefused(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/assets?page=first');

        self::assertResponseStatusCodeSame(400);
    }

    public function testAKnownAssetIsReadOnItsOwn(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/assets/BTC');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('cache-control'));
        $body = $this->decode();
        self::assertSame('BTC', $body['code']);
        // The step is a string, never a JSON number: a native number would put
        // a binary float back on the wire the moment a client parses it.
        self::assertSame(['value' => '0.00000001', 'assetCode' => 'BTC'], $body['displayStep']);
    }

    public function testAnUnknownOrMalformedCodeAnswersNotFound(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        foreach (['XAU', 'eur', 'not-a-code'] as $code) {
            $this->client->request('GET', '/api/v1/assets/'.$code);

            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('content-type', 'application/problem+json');
        }
    }

    private function signIn(string $email): void
    {
        $this->client->request(
            'POST',
            '/api/v1/session',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => WorkspaceFixture::OWNER_PASSWORD], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @return array<mixed>
     */
    private function decode(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
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

    /**
     * @param list<array<mixed>> $items
     *
     * @return array<mixed>
     */
    private static function assetNamed(array $items, string $code): array
    {
        foreach ($items as $item) {
            if ($code === ($item['code'] ?? null)) {
                return $item;
            }
        }

        self::fail(sprintf('The catalogue page does not contain %s.', $code));
    }

    /**
     * Login throttling is per email and address; without this a full run would
     * lock the shared fixture account out of the later tests.
     */
    private function resetLoginThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}
