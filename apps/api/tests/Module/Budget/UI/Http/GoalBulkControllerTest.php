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

final class GoalBulkControllerTest extends WebTestCase
{
    private const string TODAY = '2026-11-14T09:12:04+00:00';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private MockClock $clock;
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
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->requestJson('POST', '/api/v1/session', ['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);

        $this->clock = new MockClock(new \DateTimeImmutable(self::TODAY));
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        self::getContainer()->set(ClockInterface::class, $this->clock);
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

    public function testStopsOnlySelectedGoalsAndPreservesTheirPastDefinitions(): void
    {
        $ids = [$this->createGoal(), $this->createGoal(), $this->createGoal()];
        $other = $this->createGoal();
        $body = $this->batch($ids, '2026-11');
        $this->requestJson('POST', '/api/v1/goals/bulk-stop', $body);
        self::assertResponseStatusCodeSame(204);
        foreach ($ids as $id) {
            self::assertSame('2026-11', $this->scalar('SELECT end_period FROM budget_goals WHERE id = ?', [$id]));
            self::assertSame('2', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$id]));
        }
        self::assertSame('1', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$other]));
        self::assertSame('4', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        $this->requestJson('POST', '/api/v1/goals/bulk-stop', $body);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('4', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
    }

    public function testDeleteRemovesSelectedGoalsAndVersionsOnly(): void
    {
        $ids = [$this->createGoal(), $this->createGoal()];
        $other = $this->createGoal();
        $this->requestJson('POST', '/api/v1/goals/bulk-delete', $this->batch($ids));
        self::assertResponseStatusCodeSame(204);
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goals'));
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        self::assertSame('1', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$other]));
        $this->requestJson('POST', '/api/v1/goals/bulk-delete', $this->batch($ids));
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidLastStopRollsBackEarlierStopAndAudit(): void
    {
        $ids = [$this->createGoal(), $this->createGoal()];
        sort($ids);
        $body = $this->batch($ids, '2026-11');
        $body['items'][1]['lastPeriod'] = '2025-12';
        $before = $this->scalar('SELECT count(*) FROM audit_events');
        $this->requestJson('POST', '/api/v1/goals/bulk-stop', $body);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($ids[1], $this->decode()['goalId']);
        self::assertSame('lastPeriod', $this->decode()['field']);
        self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goals WHERE version = 1 AND end_period IS NULL'));
        self::assertSame($before, $this->scalar('SELECT count(*) FROM audit_events'));
    }

    public function testClosedMonthsAndStaleRowsPreventEveryWrite(): void
    {
        $ids = [$this->createGoal(), $this->createGoal()];
        foreach (['bulk-stop', 'bulk-delete'] as $action) {
            $body = $this->batch($ids, '2026-10');
            if ('bulk-delete' === $action) {
                $body = $this->batch($ids);
            }
            $body['items'][1]['expectedVersion'] = 2;
            $this->requestJson('POST', '/api/v1/goals/'.$action, $body);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('stale_goal_version', $this->decode()['code']);
        }
        $this->connection->insert('account_period_closures', ['id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'year' => 2026, 'month' => 11, 'closed_at' => '2026-11-14 12:00:00+00', 'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1]);
        $this->requestJson('POST', '/api/v1/goals/bulk-stop', $this->batch($ids, '2026-10'));
        self::assertResponseStatusCodeSame(409);
        $this->requestJson('POST', '/api/v1/goals/bulk-delete', $this->batch($ids));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goals WHERE version = 1 AND end_period IS NULL'));
    }

    public function testForeignAndMissingSelectionsAreIndistinguishableAndCsrfIsRequired(): void
    {
        $id = $this->createGoal();
        $foreign = '00000000-0000-7000-8000-0000000000e9';
        $this->connection->insert('budget_goals', ['id' => $foreign, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'label' => 'Foreign private goal', 'periodicity' => 'MONTHLY', 'metric' => 'TOTAL_SPENDING', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'start_period' => '2026-01', 'end_period' => null, 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID, 'version' => 1]);
        foreach (['bulk-stop', 'bulk-delete'] as $action) {
            foreach ([$foreign, '00000000-0000-7000-8000-0000000000ff'] as $other) {
                $this->requestJson('POST', '/api/v1/goals/'.$action, $this->batch([$id, $other], 'bulk-stop' === $action ? '2026-11' : null));
                self::assertResponseStatusCodeSame(404);
                self::assertArrayNotHasKey('goalId', $this->decode());
                self::assertStringNotContainsString('Foreign private goal', (string) $this->client->getResponse()->getContent());
            }
        }
        self::assertSame('1', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$id]));
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'invalid');
        $this->requestJson('POST', '/api/v1/goals/bulk-delete', $this->batch([$id]));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSelectionIsBoundedDistinctAndPeriodsRespectGoalPeriodicity(): void
    {
        $id = $this->createGoal();
        foreach ([[], [$id, $id], array_fill(0, 101, $id), ['invalid']] as $ids) {
            $this->requestJson('POST', '/api/v1/goals/bulk-delete', $this->batch($ids));
            self::assertResponseStatusCodeSame(422);
        }
        $this->requestJson('POST', '/api/v1/goals/bulk-stop', $this->batch([$id], '2026'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('1', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$id]));
    }

    public function testYearlyStopPreservesEarlierSchedulesAndRemovesFutureDefinitions(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Annual', 'periodicity' => 'YEARLY', 'metric' => 'TOTAL_SPENDING', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '1200.00', 'assetCode' => 'EUR'], 'schedule' => ['mode' => 'LINEAR']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['version' => 1, 'label' => 'Annual', 'effectiveFrom' => '2027-01', 'targetAmount' => ['value' => '2400.00', 'assetCode' => 'EUR']]);
        self::assertResponseIsSuccessful();
        $this->requestJson('POST', '/api/v1/goals/bulk-stop', ['items' => [['goalId' => $id, 'expectedVersion' => 2, 'lastPeriod' => '2026']]]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('2026', $this->scalar('SELECT end_period FROM budget_goals WHERE id = ?', [$id]));
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));
        self::assertSame('12', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts'));
    }

    public function testLateClosedDeleteRollsBackPreviouslyDeletedGoalHistoryAndAudit(): void
    {
        $ids = [$this->createGoal(), $this->createGoal()];
        sort($ids);
        $this->connection->update('budget_goals', ['start_period' => '2026-11'], ['id' => $ids[0]]);
        $this->connection->insert('account_period_closures', ['id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'year' => 2026, 'month' => 10, 'closed_at' => '2026-11-14 12:00:00+00', 'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1]);
        $before = $this->scalar('SELECT count(*) FROM audit_events');
        $this->requestJson('POST', '/api/v1/goals/bulk-delete', $this->batch($ids));
        self::assertResponseStatusCodeSame(409);
        self::assertSame($ids[1], $this->decode()['goalId']);
        self::assertSame('goal_covers_closed_period', $this->decode()['code']);
        self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goals'));
        self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        self::assertSame($before, $this->scalar('SELECT count(*) FROM audit_events'));
    }

    /** @return iterable<string, array{bool}> */
    public static function concurrentCases(): iterable
    {
        yield 'concurrent saves' => [false];
        yield 'concurrent closure' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('concurrentCases')]
    public function testConcurrentBatchesInOppositeOrderSerializeWithoutPartialWrites(bool $close): void
    {
        $ids = [$this->createGoal(), $this->createGoal()];
        $id = $ids[0];
        $barrier = sys_get_temp_dir().'/cadran-goal-update-'.bin2hex(random_bytes(8));
        $processes = [];
        $pipes = [];

        try {
            $this->connection->beginTransaction();
            $this->connection->fetchOne(
                'SELECT id FROM budget_goals WHERE workspace_id = ? AND id = ? FOR UPDATE',
                [WorkspaceFixture::OWN_WORKSPACE, $id],
            );
            foreach (['10.00', '20.00'] as $worker => $amount) {
                $workerPipes = [];
                $process = proc_open(
                    [PHP_BINARY, 'tests/Support/ConcurrentBulkGoalUpdateWorker.php', implode(',', 0 === $worker ? $ids : array_reverse($ids)), $amount, $barrier, (string) $worker],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $workerPipes,
                    dirname(__DIR__, 5),
                    null,
                );
                self::assertIsResource($process);
                $processes[] = $process;
                $pipes[] = $workerPipes;
            }
            $deadline = microtime(true) + 10;
            while (2 !== count(glob($barrier.'.calling.*') ?: []) && microtime(true) < $deadline) {
                usleep(1_000);
            }
            self::assertCount(2, glob($barrier.'.calling.*') ?: []);
            usleep(50_000);
            if ($close) {
                $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'period-closure:'.WorkspaceFixture::OWN_WORKSPACE]);
                $this->connection->insert('account_period_closures', ['id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'year' => 2026, 'month' => 11, 'closed_at' => '2026-11-14 12:00:00+00', 'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1]);
            }
            $this->connection->commit();

            $outcomes = [];
            foreach ($processes as $index => $process) {
                $stdout = stream_get_contents($pipes[$index][1]);
                $stderr = stream_get_contents($pipes[$index][2]);
                self::assertSame(0, proc_close($process), $stderr);
                $processes[$index] = null;
                /** @var array{outcome: string} $response */
                $response = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
                $outcomes[] = $response['outcome'];
            }
            sort($outcomes);
            self::assertSame($close ? ['goal_closed_period', 'goal_closed_period'] : ['ok', 'stale_goal_version'], $outcomes);
            self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
            foreach ($ids as $selected) {
                self::assertSame($close ? '1' : '2', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$selected]));
            }
            self::assertSame($close ? '1' : '2', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$id]));
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            foreach (glob($barrier.'.*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    private function createGoal(string $amount = '1000.00'): string
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Goal', 'periodicity' => 'MONTHLY', 'metric' => 'TOTAL_SPENDING', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-01', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => $amount, 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);

        return $this->id($this->decode());
    }

    /** @param list<string> $ids
     * @return array{items: list<array<string, mixed>>}
     */
    private function batch(array $ids, ?string $lastPeriod = null): array
    {
        return ['items' => array_map(static fn (string $id): array => ['goalId' => $id, 'expectedVersion' => 1, ...(null === $lastPeriod ? [] : ['lastPeriod' => $lastPeriod])], $ids)];
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

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return self::arr($decoded);
    }

    /** @param array<string, mixed> $payload */
    private function id(array $payload): string
    {
        $id = $payload['id'] ?? null;
        self::assertIsString($id);

        return $id;
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
}
