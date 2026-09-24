<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MonthlyRecapPreferencesControllerTest extends WebTestCase
{
    private const string URI = '/api/v1/reports/monthly/recap-preferences';
    private const string OWN_CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c9';
    private const array ALL_AXES = ['DISCRETIONARY', 'ESSENTIAL', 'FIXED', 'PERSONAL', 'PROFESSIONAL', 'VARIABLE'];

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

    public function testAWorkspaceWithoutAStoredRowReadsTheDefaultSelectionAtVersionZero(): void
    {
        $this->signIn();

        $preferences = $this->read();

        self::assertSame([], $preferences['visibleCategoryIds']);
        self::assertSame(self::ALL_AXES, $preferences['visibleAxes']);
        self::assertSame(0, $preferences['version']);
    }

    public function testAFirstSaveInsertsTheRowAndTheNextReadReturnsIt(): void
    {
        $this->signIn();
        $this->category(self::OWN_CATEGORY, WorkspaceFixture::OWN_WORKSPACE);

        $saved = $this->save([self::OWN_CATEGORY], ['ESSENTIAL', 'FIXED'], 0);

        self::assertSame([self::OWN_CATEGORY], $saved['visibleCategoryIds']);
        self::assertSame(['ESSENTIAL', 'FIXED'], $saved['visibleAxes']);
        self::assertSame(1, $saved['version']);
        self::assertSame($saved, $this->read());
    }

    public function testAnExplicitlyEmptySelectionIsStoredAsSuchAndNotRewrittenIntoTheDefault(): void
    {
        $this->signIn();
        $this->save([], [], 0);

        $preferences = $this->read();

        self::assertSame([], $preferences['visibleCategoryIds']);
        self::assertSame([], $preferences['visibleAxes']);
        self::assertSame(1, $preferences['version']);
    }

    public function testTwoConcurrentEditsFromTheSameReadLeaveOnlyTheFirstOneApplied(): void
    {
        $this->signIn();
        $this->category(self::OWN_CATEGORY, WorkspaceFixture::OWN_WORKSPACE);
        $this->save([self::OWN_CATEGORY], ['FIXED'], 0);

        $this->put([], ['ESSENTIAL'], 1);
        self::assertResponseStatusCodeSame(200);

        $this->put([], ['PERSONAL'], 1);
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');

        $preferences = $this->read();
        self::assertSame(['ESSENTIAL'], $preferences['visibleAxes']);
        self::assertSame(2, $preferences['version']);
    }

    public function testACategoryOfAnotherWorkspaceIsRefusedRatherThanStored(): void
    {
        $this->signIn();
        $this->category(self::OTHER_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE);

        $this->put([self::OTHER_CATEGORY], [], 0);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->read()['version']);
    }

    public function testAnUnknownAxisAndAnOversizedSelectionAreBothRefused(): void
    {
        $this->signIn();

        $this->put([], ['NOT_AN_AXIS'], 0);
        self::assertResponseStatusCodeSame(422);

        $this->put([], ['FIXED', 'FIXED'], 0);
        self::assertResponseStatusCodeSame(422);

        $identifiers = [];
        for ($index = 0; $index < 101; ++$index) {
            $identifiers[] = sprintf('00000000-0000-7000-8000-%012d', $index);
        }
        $this->put($identifiers, [], 0);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->read()['version']);
    }

    public function testOneWorkspaceNeverReadsOrOverwritesTheSelectionOfAnother(): void
    {
        $this->signIn();
        $this->category(self::OWN_CATEGORY, WorkspaceFixture::OWN_WORKSPACE);
        $this->save([self::OWN_CATEGORY], ['FIXED'], 0);

        $this->client->request('DELETE', '/api/v1/session');
        self::assertResponseStatusCodeSame(204);
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);

        $stranger = $this->read();
        self::assertSame([], $stranger['visibleCategoryIds']);
        self::assertSame(self::ALL_AXES, $stranger['visibleAxes']);
        self::assertSame(0, $stranger['version']);
        self::assertStringNotContainsString(self::OWN_CATEGORY, (string) $this->client->getResponse()->getContent());

        $this->save([], ['PERSONAL'], 0);

        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn();
        $own = $this->read();
        self::assertSame([self::OWN_CATEGORY], $own['visibleCategoryIds']);
        self::assertSame(['FIXED'], $own['visibleAxes']);
        self::assertSame(1, $own['version']);
    }

    public function testASaveWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->signIn();
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'forged');

        $this->put([], ['FIXED'], 0);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAnonymousCallerReadsAndWritesNothing(): void
    {
        $this->client->request('GET', self::URI);
        self::assertResponseStatusCodeSame(401);
        $this->put([], ['FIXED'], 0);
        self::assertResponseStatusCodeSame(401);
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

    /**
     * @param list<string> $categoryIds
     * @param list<string> $axes
     *
     * @return array<string, mixed>
     */
    private function save(array $categoryIds, array $axes, int $version): array
    {
        $this->put($categoryIds, $axes, $version);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /**
     * @param list<string> $categoryIds
     * @param list<string> $axes
     */
    private function put(array $categoryIds, array $axes, int $version): void
    {
        $this->client->request('PUT', self::URI, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'visibleCategoryIds' => $categoryIds,
            'visibleAxes' => $axes,
            'version' => $version,
        ], JSON_THROW_ON_ERROR));
    }

    private function category(string $id, string $workspace): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspace, 'type' => 'EXPENSE', 'label' => 'Budget '.substr($id, -2),
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
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
