<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BudgetControllerTest extends WebTestCase
{
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

        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->signIn();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testItCreatesListsEditsAndReadsAnEmptyPlan(): void
    {
        $plan = $this->createPlan();

        $this->client->request('GET', '/api/v1/budget-plans');
        self::assertResponseIsSuccessful();
        $page = $this->decode();
        self::assertSame(1, $page['total']);
        self::assertIsList($page['items']);
        self::assertCount(1, $page['items']);
        $planId = $this->id($plan);

        $this->client->request('GET', '/api/v1/budget-plans/'.$planId);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode()['targets']);

        $this->requestJson('PUT', '/api/v1/budget-plans/'.$planId, [
            'periodType' => 'YEAR',
            'period' => '2027',
            'assetCode' => 'USD',
            'version' => 1,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('2027', $this->decode()['period']);
        self::assertSame(2, $this->decode()['version']);
    }

    public function testItCreatesAndEditsAnExactTargetThenReturnsTheResolvedDetail(): void
    {
        $categoryId = '00000000-0000-7000-8000-0000000000c1';
        $this->insertCategory($categoryId, WorkspaceFixture::OWN_WORKSPACE);
        $plan = $this->createPlan();
        $planId = $this->id($plan);

        $this->requestJson('POST', '/api/v1/budget-plans/'.$planId.'/targets', [
            'scopeType' => 'CATEGORY',
            'scopeId' => $categoryId,
            'valueType' => 'AMOUNT',
            'amount' => '300.00',
            'ratio' => null,
        ]);
        self::assertResponseStatusCodeSame(201);
        $target = $this->decode();
        self::assertSame('300.00', $target['amount']);

        $this->requestJson('PUT', '/api/v1/budget-targets/'.$this->id($target), [
            'valueType' => 'AMOUNT',
            'amount' => '450.125',
            'ratio' => null,
            'version' => 1,
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/budget-plans/'.$planId);
        self::assertResponseIsSuccessful();
        $detail = $this->decode();
        $targetDetail = $this->firstTarget($detail);
        self::assertSame('450.125', $targetDetail['resolvedAmount']);
        self::assertFalse($targetDetail['overlapping']);
    }

    public function testARatioWithoutAnIncomeDenominatorIsExplicitlyNonCalculable(): void
    {
        $plan = $this->createPlan();
        $planId = $this->id($plan);
        $this->requestJson('POST', '/api/v1/budget-plans/'.$planId.'/targets', [
            'scopeType' => 'AXIS',
            'scopeId' => 'ESSENTIAL',
            'valueType' => 'RATIO',
            'amount' => null,
            'ratio' => '0.30',
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/v1/budget-plans/'.$planId);
        $target = $this->firstTarget($this->decode());
        self::assertNull($target['resolvedAmount']);
        self::assertSame('NO_ACCOUNT', $target['nonCalculableReason']);
    }

    public function testActivationRejectsAnEmptyPlanAndLifecycleChangesAreAudited(): void
    {
        $categoryId = '00000000-0000-7000-8000-0000000000c1';
        $this->insertCategory($categoryId, WorkspaceFixture::OWN_WORKSPACE);
        $plan = $this->createPlan();
        $planId = $this->id($plan);

        $this->requestJson('POST', '/api/v1/budget-plans/'.$planId.'/activate', []);
        self::assertResponseStatusCodeSame(422);

        $this->requestJson('POST', '/api/v1/budget-plans/'.$planId.'/targets', [
            'scopeType' => 'CATEGORY', 'scopeId' => $categoryId, 'valueType' => 'AMOUNT', 'amount' => '100', 'ratio' => null,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->requestJson('POST', '/api/v1/budget-plans/'.$planId.'/activate', []);
        self::assertResponseIsSuccessful();
        self::assertSame('ACTIVE', $this->decode()['state']);
        $this->requestJson('POST', '/api/v1/budget-plans/'.$planId.'/close', []);
        self::assertResponseIsSuccessful();
        self::assertSame('CLOSED', $this->decode()['state']);

        $events = $this->connection->fetchFirstColumn(
            'SELECT event_type FROM audit_events WHERE workspace_id = ? AND entity_id = ? ORDER BY occurred_at',
            [WorkspaceFixture::OWN_WORKSPACE, $planId],
        );
        self::assertSame(['budget_plan.created', 'budget_plan.activated', 'budget_plan.closed'], $events);
    }

    public function testAForeignPlanAnswersExactlyLikeAnUnknownPlan(): void
    {
        $foreignId = '00000000-0000-7000-8000-0000000000e9';
        $this->insertPlan($foreignId, WorkspaceFixture::OTHER_WORKSPACE);

        $this->client->request('GET', '/api/v1/budget-plans/'.$foreignId);
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/api/v1/budget-plans/00000000-0000-7000-8000-0000000000e8');
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());
    }

    public function testMutationsRequireCsrfAndRejectMassAssignment(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->requestJson('POST', '/api/v1/budget-plans', $this->planPayload());
        self::assertResponseStatusCodeSame(403);

        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->requestJson('POST', '/api/v1/budget-plans', [...$this->planPayload(), 'workspaceId' => WorkspaceFixture::OTHER_WORKSPACE]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPlanCreationRejectsUnknownAndNonFiatAssetsAsUnprocessable(): void
    {
        foreach (['ZZZ', 'BTC'] as $assetCode) {
            $this->requestJson('POST', '/api/v1/budget-plans', [
                'periodType' => 'MONTH',
                'period' => '2026-09',
                'assetCode' => $assetCode,
            ]);

            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testTargetCreationRejectsAnAmountBeyondThePlanAssetPrecision(): void
    {
        $categoryId = '00000000-0000-7000-8000-0000000000c1';
        $this->insertCategory($categoryId, WorkspaceFixture::OWN_WORKSPACE);
        $planId = $this->id($this->createPlan());

        $this->requestJson('POST', '/api/v1/budget-plans/'.$planId.'/targets', [
            'scopeType' => 'CATEGORY',
            'scopeId' => $categoryId,
            'valueType' => 'AMOUNT',
            'amount' => '1.123456789',
            'ratio' => null,
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** @return array<string, mixed> */
    private function createPlan(): array
    {
        $this->requestJson('POST', '/api/v1/budget-plans', $this->planPayload());
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $response */
    private function id(array $response): string
    {
        self::assertIsString($response['id'] ?? null);

        return $response['id'];
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    private function firstTarget(array $detail): array
    {
        self::assertIsList($detail['targets'] ?? null);
        self::assertIsArray($detail['targets'][0] ?? null);

        $typed = [];
        foreach ($detail['targets'][0] as $key => $value) {
            self::assertIsString($key);
            $typed[$key] = $value;
        }

        return $typed;
    }

    /** @return array<string, mixed> */
    private function planPayload(): array
    {
        return ['periodType' => 'MONTH', 'period' => '2026-09', 'assetCode' => 'EUR'];
    }

    /** @param array<string, mixed> $body */
    private function requestJson(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function signIn(): void
    {
        $this->requestJson('POST', '/api/v1/session', [
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ]);
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $typed = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $typed[$key] = $value;
        }

        return $typed;
    }

    private function insertCategory(string $id, string $workspaceId): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspaceId, 'type' => 'EXPENSE', 'label' => 'Food',
            'parent_id' => null, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]',
            'budget_included' => true, 'sort_order' => 0, 'depth' => 1, 'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00',
        ]);
    }

    private function insertPlan(string $id, string $workspaceId): void
    {
        $this->connection->insert('budget_plans', [
            'id' => $id, 'workspace_id' => $workspaceId, 'period_type' => 'MONTH', 'period' => '2026-09-01',
            'asset_code' => 'EUR', 'state' => 'DRAFT', 'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00',
        ]);
    }

    /** @return array<string, string> */
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
}
