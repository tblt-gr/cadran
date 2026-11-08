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
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class GoalLifecycleControllerTest extends WebTestCase
{
    private const string TODAY = '2026-09-14T09:12:04+00:00';
    private const string FOREIGN_GOAL = '00000000-0000-7000-8000-0000000000e9';

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
        $this->fixture = new WorkspaceFixture($this->connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $this->refreshCsrf();
        $this->requestJson('POST', '/api/v1/session', ['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);

        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        self::getContainer()->set(ClockInterface::class, new MockClock(new \DateTimeImmutable(self::TODAY)));
        $this->refreshCsrf();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testStoppingAGoalRemovesItFromLaterEvaluationsAndIsAudited(): void
    {
        $id = $this->createMonthly('2026-01');

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => 1, 'lastPeriod' => '2026-07']);

        self::assertResponseIsSuccessful();
        $stopped = $this->decode();
        self::assertSame('2026-07', $stopped['endPeriod']);
        self::assertSame(2, $stopped['version']);
        $this->client->request('GET', '/api/v1/goals/evaluations?from=2026-07&to=2026-09');
        self::assertResponseIsSuccessful();
        $months = self::list($this->decode()['months']);
        self::assertCount(3, $months);
        self::assertCount(1, self::list(self::arr($months[0])['items']));
        self::assertSame([], self::list(self::arr($months[1])['items']));
        self::assertSame([], self::list(self::arr($months[2])['items']));
        self::assertSame('1', $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'goals.stopped' AND entity_id = ?", [$id]));
        self::assertSame('0', $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'goals.stopped' AND (before_json::text LIKE '%Rent%' OR after_json::text LIKE '%Rent%' OR after_json::text LIKE '%100.00%')"));
    }

    public function testStoppingBeforeAClosedMonthTheGoalCoversIsRefused(): void
    {
        $id = $this->createMonthly('2026-01');
        $this->closeMonth(2026, 6, '00000000-0000-7000-8000-0000000000a1');

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => 1, 'lastPeriod' => '2026-05']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_closed_period', $this->decode()['code']);
        self::assertSame('', $this->scalar('SELECT coalesce(end_period, \'\') FROM budget_goals WHERE id = ?', [$id]));

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => 1, 'lastPeriod' => '2026-08']);
        self::assertResponseIsSuccessful();
    }

    public function testStopValidatesThePeriodTheVersionAndTheWorkspace(): void
    {
        $id = $this->createMonthly('2026-03');

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => 1, 'lastPeriod' => '2026-02']);
        self::assertResponseStatusCodeSame(400);
        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => 1, 'lastPeriod' => '2026']);
        self::assertResponseStatusCodeSame(400);
        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => 1, 'lastPeriod' => '2026-05']);
        self::assertResponseIsSuccessful();
        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => 2, 'lastPeriod' => '2026-06']);
        self::assertResponseStatusCodeSame(400);
        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => 1, 'lastPeriod' => '2026-04']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_goal_version', $this->decode()['code']);

        $this->insertForeignGoal();
        $this->requestJson('POST', '/api/v1/goals/'.self::FOREIGN_GOAL.'/stop', ['expectedVersion' => 1, 'lastPeriod' => '2026']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('', $this->scalar("SELECT coalesce(end_period, '') FROM budget_goals WHERE id = ? AND version = 1", [self::FOREIGN_GOAL]));
    }

    public function testDeletingAGoalOverAClosedMonthIsRefusedAndAFreshGoalLeavesNothingBehind(): void
    {
        $covered = $this->createMonthly('2026-01');
        $this->closeMonth(2026, 3, '00000000-0000-7000-8000-0000000000a1');

        $this->deleteGoal($covered, '1');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_covers_closed_period', $this->decode()['code']);

        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Yearly fresh', 'periodicity' => 'YEARLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2027', 'endPeriod' => '2027', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '1200.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $fresh = $this->decode()['id'];
        self::assertIsString($fresh);
        self::assertSame('12', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]));

        $this->deleteGoal($fresh, '1');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goals WHERE id = ?', [$fresh]));
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$fresh]));
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]));
        self::assertSame('1', $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'goals.deleted' AND entity_id = ?", [$fresh]));
        self::assertSame('0', $this->scalar("SELECT count(*) FROM audit_events WHERE event_type = 'goals.deleted' AND (after_json::text LIKE '%Yearly fresh%' OR after_json::text LIKE '%1200%')"));
    }

    public function testDeleteNeedsAFreshVersionAndNeverTouchesAnotherWorkspace(): void
    {
        $id = $this->createMonthly('2026-09');

        $this->client->request('DELETE', '/api/v1/goals/'.$id);
        self::assertResponseStatusCodeSame(428);
        $this->deleteGoal($id, 'abc');
        self::assertResponseStatusCodeSame(400);
        $this->deleteGoal($id, '7');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_goal_version', $this->decode()['code']);
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goals WHERE id = ?', [$id]));

        $this->insertForeignGoal();
        $this->deleteGoal(self::FOREIGN_GOAL, '1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goals WHERE id = ?', [self::FOREIGN_GOAL]));
    }

    public function testEvaluationsBoundTheRangeAndHideForeignGoals(): void
    {
        $this->createMonthly('2026-01');
        $this->insertForeignGoal();

        foreach (['from=2026-01&to=2036-02', 'from=2026-05&to=2026-04', 'from=2026-01', 'from=x&to=y', 'from=2026-01&to=2026-12&granularity=WEEK', 'from=2026-01&to=2056-12&granularity=YEAR'] as $query) {
            $this->client->request('GET', '/api/v1/goals/evaluations?'.$query);
            self::assertResponseStatusCodeSame(400, $query);
        }
        $this->client->request('GET', '/api/v1/goals/evaluations?from=2026-08&to=2026-12');
        self::assertResponseIsSuccessful();
        $payload = $this->decode();
        $months = self::list($payload['months']);
        self::assertCount(2, $months);
        $item = self::arr(self::list(self::arr($months[0])['items'])[0]);
        self::assertSame('Rent cap', $item['label']);
        self::assertSame('NET_SAVINGS_TRANSFERS', $item['metric']);
        self::assertNotContains('Foreign', array_map(static fn (mixed $entry): mixed => self::arr($entry)['label'], self::list(self::arr($months[0])['items'])));
        self::assertSame([], self::list(self::arr(self::list($payload['years'])[0])['items']));
        $this->client->request('GET', '/api/v1/goals/evaluations?from=2026-01&to=2026-12&granularity=YEAR');
        self::assertResponseIsSuccessful();
        $yearOnly = $this->decode();
        self::assertSame([], $yearOnly['months']);
        self::assertCount(1, self::list($yearOnly['years']));
    }

    public function testARevisionTakingEffectNextMonthLeavesThePastAtItsTarget(): void
    {
        $id = $this->createMonthly('2026-01');

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rent cap', 'targetAmount' => ['value' => '110.00', 'assetCode' => 'EUR'], 'version' => 1, 'effectiveFrom' => '2026-12']);

        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/goals/evaluations?from=2026-07&to=2026-09');
        $targets = array_map(static fn (mixed $month): mixed => self::arr(self::arr(self::list(self::arr($month)['items'])[0])['target'])['value'], self::list($this->decode()['months']));
        self::assertSame(['100.00', '100.00', '100.00'], $targets);
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rent cap', 'targetAmount' => ['value' => '120.00', 'assetCode' => 'EUR'], 'version' => 2, 'effectiveFrom' => '2026-02']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('correction_reason_required', $this->decode()['code']);
        $this->closeMonth(2026, 2, '00000000-0000-7000-8000-0000000000a2');
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rent cap', 'targetAmount' => ['value' => '120.00', 'assetCode' => 'EUR'], 'version' => 2, 'effectiveFrom' => '2026-02', 'reason' => 'Fix the target.']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_closed_period', $this->decode()['code']);
    }

    public function testStoppingBeforeAPlannedRevisionRemovesTheOrphanVersion(): void
    {
        $id = $this->createMonthly('2026-01');
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rent cap', 'targetAmount' => ['value' => '110.00', 'assetCode' => 'EUR'], 'version' => 1, 'effectiveFrom' => '2026-12']);
        self::assertResponseIsSuccessful();
        self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => 2, 'lastPeriod' => '2026-10']);

        self::assertResponseIsSuccessful();
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));
        self::assertCount(1, self::list($this->decode()['versions']));
    }

    public function testARevisionCannotBeShadowedByAnAlreadyPlannedOne(): void
    {
        $id = $this->createMonthly('2026-01');
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rent cap', 'targetAmount' => ['value' => '110.00', 'assetCode' => 'EUR'], 'version' => 1, 'effectiveFrom' => '2026-12']);
        self::assertResponseIsSuccessful();

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rent cap', 'targetAmount' => ['value' => '120.00', 'assetCode' => 'EUR'], 'version' => 2]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_planned_revision_exists', $this->decode()['code']);
        self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rent cap', 'targetAmount' => ['value' => '120.00', 'assetCode' => 'EUR'], 'version' => 2, 'effectiveFrom' => '2026-12']);
        self::assertResponseIsSuccessful();
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rent cap', 'targetAmount' => ['value' => '130.00', 'assetCode' => 'EUR'], 'version' => 3, 'effectiveFrom' => '2027-02']);
        self::assertResponseIsSuccessful();
    }

    public function testANonIntegerExpectedVersionIsAMalformedQuery(): void
    {
        $id = $this->createMonthly('2026-01');

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/stop', ['expectedVersion' => '1', 'lastPeriod' => '2026-05']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_goal_query', $this->decode()['code']);
    }

    private function createMonthly(string $start): string
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Rent cap', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => $start, 'endPeriod' => null, 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '100.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->decode()['id'];
        self::assertIsString($id);

        return $id;
    }

    private function deleteGoal(string $id, string $version): void
    {
        $this->client->request('DELETE', '/api/v1/goals/'.$id, server: ['HTTP_IF_MATCH' => $version]);
    }

    private function closeMonth(int $year, int $month, string $id): void
    {
        $this->connection->insert('account_period_closures', ['id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'year' => $year, 'month' => $month, 'closed_at' => '2026-09-01 10:00:00+00', 'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1]);
    }

    private function insertForeignGoal(): void
    {
        $this->connection->insert('budget_goals', ['id' => self::FOREIGN_GOAL, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'label' => 'Foreign', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'start_period' => '2026-01', 'end_period' => null, 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID, 'version' => 1]);
        $this->connection->insert('budget_goal_versions', ['id' => '00000000-0000-7000-8000-0000000000f9', 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'goal_id' => self::FOREIGN_GOAL, 'version_no' => 1, 'effective_from' => '2026-01', 'value_type' => 'AMOUNT', 'target_amount' => '100.00', 'target_scale' => 2, 'ratio' => null, 'ratio_scale' => null, 'schedule_mode' => null, 'reason' => null, 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID]);
    }

    /** @param array<string, mixed> $body */
    private function requestJson(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $parameters */
    private function scalar(string $sql, array $parameters = []): string
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsScalar($value);

        return (string) $value;
    }

    private function refreshCsrf(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        return self::arr(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);
        $fields = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $fields[$key] = $item;
        }

        return $fields;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }
}
