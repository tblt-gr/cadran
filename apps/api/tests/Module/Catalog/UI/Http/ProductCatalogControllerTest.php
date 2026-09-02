<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The HTTP surface of PRD-001. The catalogue is global on purpose, so the
 * isolation question it must answer is the mirror of the usual one: every
 * workspace reads the same products, and the catalogue carries nothing of its
 * own.
 */
final class ProductCatalogControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        self::mockTime('2026-09-02 12:00:00 UTC');
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
        $this->client->request('GET', '/api/v1/products');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAProductIsReadWithItsSourcedRuleOnTheBusinessDate(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products/FR_LIVRET_A?asOf=2026-09-02');

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame('Livret A', $body['displayName']);
        self::assertSame([
            'SUPPORTS_BALANCE',
            'SUPPORTS_TRANSACTIONS',
            'SUPPORTS_INTEREST',
        ], $body['capabilities']);
        self::assertSame('2026-09-02', $body['asOf']);
        self::assertTrue($body['yieldGuaranteed']);
        self::assertSame([], $body['unavailableRuleKinds']);

        $rate = self::ruleOfKind($body, 'ANNUAL_RATE');
        self::assertSame('PERCENTAGE', $rate['valueType']);
        // Published as a percentage and transported as a canonical string: the
        // client renders "1,7 %" without ever multiplying a rate.
        self::assertSame('1.7', $rate['percentage']);
        self::assertNull($rate['amount']);
        self::assertSame('2026-08-01', $rate['validFrom']);
        self::assertSame('2027-01-31', $rate['validTo']);
        self::assertSame('VERIFIED', $rate['verification']);
        self::assertSame('2026-08-22', $rate['verifiedOn']);
        $source = $rate['source'];
        self::assertIsArray($source);
        self::assertIsString($source['url']);
        self::assertStringStartsWith('https://', $source['url']);

        $ceiling = self::ruleOfKind($body, 'DEPOSIT_CEILING');
        self::assertSame(['value' => '22950', 'assetCode' => 'EUR'], $ceiling['amount']);
    }

    public function testAPastBusinessDateResolvesThePeriodThatAppliedThenNotTodays(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products/FR_LIVRET_A?asOf=2026-07-31');

        self::assertResponseIsSuccessful();
        $body = $this->decode();

        // No rate has been sourced for that semester yet. The answer is
        // "unavailable", never an invented 0 %.
        self::assertSame(['ANNUAL_RATE'], $body['unavailableRuleKinds']);
        self::assertSame(['DEPOSIT_CEILING'], array_column(self::rows($body, 'rules'), 'kind'));
    }

    public function testAProductWithNoSourcedRuleReportsItsRateAsUnavailable(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products/FR_LIVRET_JEUNE');

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame([], $body['rules']);
        self::assertSame(['DEPOSIT_CEILING', 'ANNUAL_RATE'], $body['unavailableRuleKinds']);
    }

    public function testARegulatedCeilingWithoutAnEffectivePeriodRemainsVisibleAsUnavailable(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products/FR_LDDS?asOf=2026-08-21');

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame(['ANNUAL_RATE'], array_column(self::rows($body, 'rules'), 'kind'));
        self::assertSame(['DEPOSIT_CEILING'], $body['unavailableRuleKinds']);
    }

    #[DataProvider('marketProducts')]
    public function testAMarketProductNeverAnnouncesAGuaranteedYield(string $code): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products/'.$code);

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertFalse($body['yieldGuaranteed']);
        self::assertSame([], $body['unavailableRuleKinds']);
        self::assertSame([], array_filter(
            self::rows($body, 'rules'),
            static fn (array $rule): bool => 'PERCENTAGE' === ($rule['valueType'] ?? null),
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function marketProducts(): iterable
    {
        yield 'a share savings plan' => ['FR_PEA'];
        yield 'a securities account' => ['FR_CTO'];
        yield 'a life-insurance contract' => ['FR_LIFE_INSURANCE'];
    }

    public function testCapabilitiesDescribeBehaviorWithoutProductNameConditions(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products/FR_LIFE_INSURANCE');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'SUPPORTS_BALANCE',
            'SUPPORTS_TRANSACTIONS',
            'SUPPORTS_HOLDINGS',
            'SUPPORTS_ARBITRAGE',
            'SUPPORTS_CONTRIBUTIONS',
            'SUPPORTS_FEES',
            'SUPPORTS_TAX_TRACKING',
        ], $this->decode()['capabilities']);
    }

    public function testTheCatalogueIsTheSameForEveryWorkspace(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);
        $this->client->request('GET', '/api/v1/products?asOf=2026-09-02');
        $own = $this->decode();

        $this->client->request('DELETE', '/api/v1/session');
        $this->resetLoginThrottling();
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $this->client->request('GET', '/api/v1/products?asOf=2026-09-02');

        self::assertResponseIsSuccessful();
        self::assertSame($own, $this->decode());
    }

    public function testOnePageStaysInsideItsBounds(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products?page=2&perPage=3');

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame(
            ['FR_LIFE_INSURANCE', 'FR_LIVRET_A', 'FR_LIVRET_JEUNE'],
            array_column(self::rows($body, 'items'), 'code'),
        );
        self::assertSame(2, $body['page']);
        self::assertSame(3, $body['perPage']);
        self::assertSame(8, $body['total']);
    }

    #[DataProvider('unboundedQueries')]
    public function testAnUnboundedOrMalformedQueryIsRefused(string $query): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products'.$query);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unboundedQueries(): iterable
    {
        yield 'a page past the cap' => ['?page=1001'];
        yield 'a page size past the cap' => ['?perPage=101'];
        yield 'a page that is not a number' => ['?page=none'];
        yield 'a date that is not a day' => ['?asOf=2026-09'];
        yield 'a day that does not exist' => ['?asOf=2026-02-31'];
        yield 'a day outside the calendar bounds' => ['?asOf=2101-01-01'];
        yield 'a timestamp instead of a day' => ['?asOf=2026-09-02T00:00:00Z'];
    }

    public function testAnUnknownProductIsNotFound(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products/FR_PEL');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testTheCatalogueIsNeverStoredByAnIntermediary(): void
    {
        $this->signIn(WorkspaceFixture::OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/products');

        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('cache-control'));
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<mixed>
     */
    private static function ruleOfKind(array $body, string $kind): array
    {
        foreach (self::rows($body, 'rules') as $rule) {
            if ($kind === ($rule['kind'] ?? null)) {
                return $rule;
            }
        }

        self::fail(sprintf('The response carries no %s rule.', $kind));
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<array<mixed>>
     */
    private static function rows(array $body, string $key): array
    {
        $list = $body[$key] ?? null;
        self::assertIsList($list);

        $rows = [];
        foreach ($list as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
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

    private function resetLoginThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
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
}
