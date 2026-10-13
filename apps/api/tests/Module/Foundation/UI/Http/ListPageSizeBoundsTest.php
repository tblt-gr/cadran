<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every list endpoint that documents a `pageSize` query parameter rejects an out-of-range
 * value, and an out-of-range `page` or `cursor`, with a 400 problem detail. The endpoints come
 * from the OpenAPI document, so a new paginated route is covered without editing this test.
 */
final class ListPageSizeBoundsTest extends WebTestCase
{
    private KernelBrowser $client;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        (new PortfolioFixture($connection))->account();

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->client->jsonRequest('POST', '/api/v1/session', [
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ]);
        self::assertResponseStatusCodeSame(204);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function outOfRangePageSizes(): iterable
    {
        foreach (self::paginatedPaths() as $path) {
            foreach (['0', '-1', '101', 'abc', '1.5'] as $value) {
                yield $path.' pageSize='.$value => [$path, 'pageSize='.$value];
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function outOfRangePages(): iterable
    {
        foreach (self::paginatedPaths('page') as $path) {
            foreach (['0', '-1', 'abc'] as $value) {
                yield $path.' page='.$value => [$path, 'page='.$value];
            }
        }
    }

    #[DataProvider('outOfRangePageSizes')]
    #[DataProvider('outOfRangePages')]
    public function testAnOutOfRangePaginationValueIsAProblemDetail(string $path, string $query): void
    {
        $this->client->request('GET', self::url($path).'?'.$query.self::requiredQuery($path));

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    /** @return iterable<string, array{string, string}> */
    public static function validPageSizes(): iterable
    {
        foreach (self::paginatedPaths() as $path) {
            foreach (['1', '100'] as $value) {
                yield $path.' pageSize='.$value => [$path, 'pageSize='.$value];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function cursorPaths(): iterable
    {
        foreach (self::paginatedPaths('cursor') as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('validPageSizes')]
    public function testTheBoundariesOfThePageSizeAreAccepted(string $path, string $query): void
    {
        $this->client->request('GET', self::url($path).'?'.$query.self::requiredQuery($path));

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('cursorPaths')]
    public function testAMalformedCursorIsAProblemDetail(string $path): void
    {
        $this->client->request('GET', self::url($path).'?cursor=not-a-cursor'.self::requiredQuery($path));

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testThereAreSeveralPaginatedEndpoints(): void
    {
        self::assertGreaterThanOrEqual(15, count(self::paginatedPaths()));
    }

    /** @return list<string> */
    private static function paginatedPaths(string $parameter = 'pageSize'): array
    {
        $document = Yaml::parseFile(dirname(__DIR__, 5).'/openapi/openapi.yaml');
        self::assertIsArray($document);
        $documented = $document['paths'] ?? null;
        self::assertIsArray($documented);
        $paths = [];
        foreach ($documented as $path => $item) {
            self::assertIsString($path);
            self::assertIsArray($item);
            $get = $item['get'] ?? null;
            if (!is_array($get)) {
                continue;
            }
            $parameters = $get['parameters'] ?? [];
            self::assertIsArray($parameters);
            foreach ($parameters as $candidate) {
                if (is_array($candidate) && ($candidate['name'] ?? null) === $parameter && 'query' === ($candidate['in'] ?? null)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    private static function url(string $path): string
    {
        return str_replace(['{kind}', '{id}', '{accountId}'], ['account', PortfolioFixture::ACCOUNT, PortfolioFixture::ACCOUNT], $path);
    }

    private static function requiredQuery(string $path): string
    {
        return str_contains($path, '/reports/monthly/') ? '&month=2026-03' : '';
    }
}
