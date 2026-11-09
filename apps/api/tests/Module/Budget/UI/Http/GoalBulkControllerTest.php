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

    public function testPreviewAndAtomicToleranceSavePreserveTargetsAndUnselectedGoals(): void
    {
        $ids = [$this->createGoal(), $this->createGoal(), $this->createGoal()];
        $other = $this->createGoal();
        $body = $this->batch($ids, ['tolerance' => '10.00']);
        $this->requestJson('POST', '/api/v1/goals/bulk-preview', $body);
        self::assertResponseIsSuccessful();
        $preview = $this->decode();
        self::assertTrue($preview['canApply']);
        $rows = self::items($preview['items']);
        self::assertCount(3, $rows);
        self::assertSame('2026-11', self::arr($rows[0])['firstAffectedMonth']);
        self::assertNull(self::arr($rows[0])['lastAffectedMonth']);
        self::assertSame('1000.00', self::arr(self::arr($rows[0])['after'])['target']);
        self::assertSame('4', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $body);
        self::assertResponseIsSuccessful();
        self::assertSame('7', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        self::assertSame('1', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$other]));
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $body);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('7', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        $clear = $this->batch($ids, ['tolerance' => '0'], 2);
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $clear);
        self::assertResponseIsSuccessful();
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch($ids, ['tolerance' => '0'], 3));
        self::assertResponseIsSuccessful();
        self::assertSame('10', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
    }

    public function testInvalidLastItemAndInaccessibleItemRollBackTheEntireBatch(): void
    {
        $ids = [$this->createGoal(), $this->createGoal('5.00')];
        foreach (['-1', '10', '0.123456789'] as $invalid) {
            $body = $this->batch($ids, ['tolerance' => $invalid]);
            $this->requestJson('POST', '/api/v1/goals/bulk-preview', $body);
            self::assertResponseIsSuccessful();
            self::assertFalse($this->decode()['canApply']);
            $this->requestJson('POST', '/api/v1/goals/bulk-edit', $body);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('tolerance', $this->decode()['field']);
            self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        }
        $missing = '00000000-0000-7000-8000-0000000000ff';
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch([$ids[0], $missing], ['tolerance' => '1']));
        self::assertResponseStatusCodeSame(404);
        self::assertArrayNotHasKey('goalId', $this->decode());
        self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
    }

    public function testPlannedRevisionsAreShownAndBlockForwardEdit(): void
    {
        $id = $this->createGoal();
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['version' => 1, 'label' => 'Goal', 'effectiveFrom' => '2026-12', 'tolerance' => '2']);
        self::assertResponseIsSuccessful();
        $body = $this->batch([$id], ['tolerance' => '1'], 2);
        $this->requestJson('POST', '/api/v1/goals/bulk-preview', $body);
        self::assertResponseIsSuccessful();
        $row = self::arr(self::items($this->decode()['items'])[0]);
        self::assertSame('2026-11', $row['lastAffectedMonth']);
        self::assertSame('goal_planned_revision_exists', self::arr($row['conflict'])['code']);
        self::assertCount(1, self::items($row['laterRevisions']));
        $body['effectiveFrom'] = '2026-10';
        $body['reason'] = 'Historical correction';
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $body);
        self::assertResponseIsSuccessful();
        self::assertSame('3', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
    }

    public function testBoundsMissingChangesDuplicatesAndClosedMonthAreRejected(): void
    {
        $id = $this->createGoal();
        foreach ([$this->batch([], ['tolerance' => '1']), $this->batch([$id], []), $this->batch([$id, $id], ['tolerance' => '1'])] as $body) {
            $this->requestJson('POST', '/api/v1/goals/bulk-edit', $body);
            self::assertResponseStatusCodeSame(422);
        }
        $this->connection->insert('account_period_closures', ['id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'year' => 2026, 'month' => 11, 'version' => 1, 'closed_at' => '2026-11-14 12:00:00+00', 'closed_by' => WorkspaceFixture::OWNER_ID]);
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch([$id], ['tolerance' => '1']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
    }

    public function testCustomCalendarIsPreservedAndMixedTypesAreRejected(): void
    {
        $payload = ['label' => 'Annual', 'periodicity' => 'YEARLY', 'metric' => 'TOTAL_SPENDING', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '1000.00', 'assetCode' => 'EUR'], 'schedule' => ['mode' => 'CUSTOM', 'parts' => ['1000.00', ...array_fill(0, 11, '0.00')]]];
        $this->requestJson('POST', '/api/v1/goals', $payload);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());
        $original = self::arr(self::arr($this->decode()['currentVersion'])['schedule']);
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch([$id], ['tolerance' => '10.00']));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/goals/'.$id);
        self::assertResponseIsSuccessful();
        $current = self::arr($this->decode()['currentVersion']);
        self::assertSame('CUSTOM', self::arr($current['schedule'])['mode']);
        self::assertSame('1000.00', $current['targetAmount']);
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch([$id], ['target' => '2000'], 2));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('bulk_custom_schedule_target', $this->decode()['code']);
        self::assertSame($original, self::arr($current['schedule']));
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Rate', 'periodicity' => 'MONTHLY', 'metric' => 'SAVINGS_RATE', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-01', 'valueType' => 'RATE', 'ratio' => '0.15']);
        self::assertResponseStatusCodeSame(201);
        $rate = $this->id($this->decode());
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', ['items' => [['goalId' => $id, 'expectedVersion' => 2, 'expectedValueType' => 'AMOUNT', 'expectedAssetCode' => 'EUR'], ['goalId' => $rate, 'expectedVersion' => 1, 'expectedValueType' => 'RATE', 'expectedAssetCode' => 'EUR']], 'effectiveFrom' => '2026-11', 'changes' => ['tolerance' => '0.01']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('incompatible_goal_units', $this->decode()['code']);
    }

    public function testChangingTargetsPreservesDifferentTolerancesAndExactLargeValues(): void
    {
        $ids = [$this->createGoal(), $this->createGoal()];
        foreach ($ids as $index => $id) {
            $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch([$id], ['tolerance' => (string) ($index + 1)]));
            self::assertResponseIsSuccessful();
        }
        $target = '9999999999999999999999999.12345678';
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch($ids, ['target' => $target], 2));
        self::assertResponseIsSuccessful();
        foreach (self::items($this->decode()['items']) as $row) {
            self::assertSame($target, self::arr(self::arr($row)['after'])['target']);
            self::assertSame(self::arr(self::arr($row)['before'])['tolerance'], self::arr(self::arr($row)['after'])['tolerance']);
        }
    }

    public function testForeignSelectionDoesNotDiscloseOrWriteAndCsrfIsRequired(): void
    {
        $id = $this->createGoal();
        $foreign = '00000000-0000-7000-8000-0000000000e9';
        $this->connection->insert('budget_goals', ['id' => $foreign, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'label' => 'Foreign private goal', 'periodicity' => 'MONTHLY', 'metric' => 'TOTAL_SPENDING', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'start_period' => '2026-01', 'end_period' => null, 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID, 'version' => 1]);
        foreach (['bulk-preview', 'bulk-edit'] as $path) {
            $this->requestJson('POST', '/api/v1/goals/'.$path, $this->batch([$id, $foreign], ['tolerance' => '1']));
            self::assertResponseStatusCodeSame(404);
            self::assertArrayNotHasKey('goalId', $this->decode());
            self::assertStringNotContainsString('Foreign private goal', (string) $this->client->getResponse()->getContent());
        }
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'invalid');
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch([$id], ['tolerance' => '1']));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
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
            self::assertSame($close ? '2' : '4', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
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

    public function testIndependentLaterGoalIsShownButUnselectedAndCannotStartEarly(): void
    {
        $id = $this->createGoal();
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'December only', 'periodicity' => 'MONTHLY', 'metric' => 'TOTAL_SPENDING', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-12', 'endPeriod' => '2026-12', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '300.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $december = $this->id($this->decode());
        $this->requestJson('POST', '/api/v1/goals/bulk-preview', $this->batch([$id], ['tolerance' => '1']));
        self::assertResponseIsSuccessful();
        $overrides = self::items(self::arr(self::items($this->decode()['items'])[0])['overrides']);
        self::assertSame($december, self::arr($overrides[0])['goalId']);
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch([$id], ['tolerance' => '1']));
        self::assertResponseIsSuccessful();
        self::assertSame('1', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$december]));
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch([$december], ['tolerance' => '1']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('effectiveFrom', $this->decode()['field']);
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $this->batch(array_fill(0, 101, $id), ['tolerance' => '1']));
        self::assertResponseStatusCodeSame(422);
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', ['items' => [['goalId' => $id, 'expectedVersion' => 2, 'expectedValueType' => 'AMOUNT', 'expectedAssetCode' => 'EUR']], 'effectiveFrom' => '2026-10', 'changes' => ['tolerance' => '2']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('reason', $this->decode()['field']);
        self::assertSame('3', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
    }

    public function testValuesCannotBeReinterpretedInAHistoricalTargetUnit(): void
    {
        $id = $this->createGoal();
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['version' => 1, 'label' => 'Goal', 'effectiveFrom' => '2026-11', 'valueType' => 'INCOME_SHARE', 'ratio' => '0.50']);
        self::assertResponseIsSuccessful();
        foreach ([['target' => '0.02'], ['tolerance' => '0.01']] as $changes) {
            $body = ['items' => [['goalId' => $id, 'expectedVersion' => 2, 'expectedValueType' => 'INCOME_SHARE', 'expectedAssetCode' => 'EUR']], 'effectiveFrom' => '2026-10', 'changes' => $changes, 'reason' => 'Historical correction'];
            $this->requestJson('POST', '/api/v1/goals/bulk-preview', $body);
            self::assertResponseIsSuccessful();
            self::assertFalse($this->decode()['canApply']);
            $row = self::arr(self::items($this->decode()['items'])[0]);
            self::assertSame('goal_target_unit_changed', self::arr($row['conflict'])['code']);
            $this->requestJson('POST', '/api/v1/goals/bulk-edit', $body);
            self::assertResponseStatusCodeSame(409);
            self::assertSame($id, $this->decode()['goalId']);
            self::assertSame('selection', $this->decode()['field']);
            self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        }
        $body['items'] = [['goalId' => $id, 'expectedVersion' => 2, 'expectedValueType' => 'AMOUNT', 'expectedAssetCode' => 'USD']];
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $body);
        self::assertResponseStatusCodeSame(409);
        $body['items'][0]['expectedAssetCode'] = 'EUR';
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', $body);
        self::assertResponseIsSuccessful();
        self::assertSame('3', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        $this->requestJson('POST', '/api/v1/goals/bulk-edit', ['items' => [['goalId' => $id, 'expectedVersion' => 3]], 'effectiveFrom' => '2026-11', 'changes' => ['tolerance' => '0.01']]);
        self::assertResponseStatusCodeSame(422);
    }

    private function createGoal(string $amount = '1000.00'): string
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Goal', 'periodicity' => 'MONTHLY', 'metric' => 'TOTAL_SPENDING', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-01', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => $amount, 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);

        return $this->id($this->decode());
    }

    /**
     * @param list<string>          $ids
     * @param array<string, string> $changes
     *
     * @return array<string, mixed>
     */
    private function batch(array $ids, array $changes, int $version = 1): array
    {
        return ['items' => array_map(static fn (string $id): array => ['goalId' => $id, 'expectedVersion' => $version, 'expectedValueType' => 'AMOUNT', 'expectedAssetCode' => 'EUR'], $ids), 'effectiveFrom' => '2026-11', 'changes' => $changes];
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

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }
}
