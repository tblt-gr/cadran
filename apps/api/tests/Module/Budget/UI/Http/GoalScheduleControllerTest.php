<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class GoalScheduleControllerTest extends WebTestCase
{
    private const string TODAY = '2026-09-14T09:12:04+00:00';
    private const string FOREIGN_GOAL = '00000000-0000-7000-8000-0000000000e9';
    private const string FOREIGN_VERSION = '00000000-0000-7000-8000-0000000000f9';

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

    public function testAnAnnualGoalGetsALinearScheduleByDefaultWithExactRunningTotals(): void
    {
        $created = $this->create('2000');

        self::assertResponseStatusCodeSame(201);
        $schedule = self::arr(self::arr($created['currentVersion'])['schedule']);
        self::assertSame('LINEAR', $schedule['mode']);
        $parts = self::rows($schedule['parts']);
        self::assertCount(12, $parts);
        self::assertSame(['month' => '2026-01', 'amount' => '166.67', 'cumulative' => '166.67'], $parts[0]);
        self::assertSame(['month' => '2026-11', 'amount' => '166.67', 'cumulative' => '1833.37'], $parts[10]);
        self::assertSame(['month' => '2026-12', 'amount' => '166.63', 'cumulative' => '2000.00'], $parts[11]);

        $stored = $this->connection->fetchAllAssociative('SELECT workspace_id, month_index, amount_scale FROM budget_goal_schedule_parts ORDER BY month_index');
        self::assertCount(12, $stored);
        self::assertSame(WorkspaceFixture::OWN_WORKSPACE, $stored[0]['workspace_id']);
        self::assertSame('LINEAR', $this->connection->fetchOne('SELECT schedule_mode FROM budget_goal_versions'));
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts p JOIN budget_goal_versions v ON v.id = p.goal_version_id WHERE v.target_amount <> (SELECT sum(amount) FROM budget_goal_schedule_parts q WHERE q.goal_version_id = v.id)'));

        $this->client->request('GET', '/api/v1/goals?year=2026');
        $listed = self::rows($this->decode()['items']);
        self::assertSame('166.63', self::rows(self::arr(self::arr($listed[0]['currentVersion'])['schedule'])['parts'])[11]['amount']);
    }

    public function testATargetTooSmallForALinearSplitIsRefused(): void
    {
        $this->create('0.10');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('linear_schedule_impossible', $this->decode()['code']);
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goals'));
    }

    public function testATargetFinerThanTheCurrencyPrecisionCannotBeScheduled(): void
    {
        $this->create('15000.125');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('schedule_precision_exceeded', $this->decode()['code']);
    }

    public function testACustomScheduleOffByOneCentReturnsTheExactDifferenceAndPersistsNothing(): void
    {
        $this->create('2000', ['mode' => 'CUSTOM', 'parts' => [...array_fill(0, 11, '166.67'), '166.62']]);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->decode();
        self::assertSame('schedule_sum_mismatch', $problem['code']);
        self::assertSame('2000', $problem['expected']);
        self::assertSame('1999.99', $problem['actual']);
        self::assertSame('0.01', $problem['difference']);
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goals'));
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts'));
    }

    public function testAnExactCustomScheduleIsStoredAtTheCurrencyPrecision(): void
    {
        $created = $this->create('2000', ['mode' => 'CUSTOM', 'parts' => ['1000', '1000', ...array_fill(0, 10, '0')]]);

        self::assertResponseStatusCodeSame(201);
        $schedule = self::arr(self::arr($created['currentVersion'])['schedule']);
        self::assertSame('CUSTOM', $schedule['mode']);
        $parts = self::rows($schedule['parts']);
        self::assertSame(['month' => '2026-02', 'amount' => '1000.00', 'cumulative' => '2000.00'], $parts[1]);
        self::assertSame(['month' => '2026-12', 'amount' => '0.00', 'cumulative' => '2000.00'], $parts[11]);
    }

    public function testMalformedCustomSchedulesAreRejected(): void
    {
        $cases = [
            'schedule_part_count' => ['mode' => 'CUSTOM', 'parts' => array_fill(0, 11, '0')],
            'schedule_negative_part' => ['mode' => 'CUSTOM', 'parts' => ['-1', '2001', ...array_fill(0, 10, '0')]],
            'schedule_precision_exceeded' => ['mode' => 'CUSTOM', 'parts' => ['1999.995', '0.005', ...array_fill(0, 10, '0')]],
        ];
        foreach ($cases as $code => $schedule) {
            $this->create('2000', $schedule);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($code, $this->decode()['code']);
        }
        foreach ([['mode' => 'WEEKLY'], ['mode' => 'CUSTOM', 'parts' => [1, 2]], ['mode' => 'LINEAR', 'parts' => array_fill(0, 12, '0')], ['mode' => 'CUSTOM', 'parts' => array_fill(0, 13, '1e3')], 'LINEAR'] as $schedule) {
            $this->create('2000', $schedule);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goals'));
    }

    public function testARollingGoalRefusesASchedule(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Rate', 'kind' => 'ROLLING_RATIO', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'ratio' => '0.1', 'startMonth' => '2026-09', 'schedule' => ['mode' => 'LINEAR']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goals'));
    }

    public function testChangingTheTargetOfALinearGoalRecomputesThePartsInANewVersion(): void
    {
        $id = $this->id($this->create('2000'));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Savings', 'targetAmount' => ['value' => '1000', 'assetCode' => 'EUR'], 'version' => 1]);

        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        $current = self::arr($updated['currentVersion']);
        self::assertSame(2, $current['versionNo']);
        self::assertSame('83.37', self::rows(self::arr($current['schedule'])['parts'])[11]['amount']);
        $first = self::rows($updated['versions'])[0];
        self::assertSame('166.63', self::rows(self::arr($first['schedule'])['parts'])[11]['amount']);
        self::assertSame('24', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts'));
    }

    public function testChangingTheTargetOfACustomGoalRequiresNewParts(): void
    {
        $id = $this->id($this->create('2000', ['mode' => 'CUSTOM', 'parts' => ['2000', ...array_fill(0, 11, '0')]]));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Savings', 'targetAmount' => ['value' => '1000', 'assetCode' => 'EUR'], 'version' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('schedule_part_count', $this->decode()['code']);

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Savings', 'targetAmount' => ['value' => '1000', 'assetCode' => 'EUR'], 'schedule' => ['mode' => 'CUSTOM', 'parts' => ['1000', ...array_fill(0, 11, '0')]], 'version' => 1]);
        self::assertResponseIsSuccessful();
        $current = self::arr($this->decode()['currentVersion']);
        self::assertSame('CUSTOM', self::arr($current['schedule'])['mode']);
        self::assertSame('1000.00', self::rows(self::arr($current['schedule'])['parts'])[0]['amount']);
    }

    public function testChangingOnlyTheScheduleCreatesANewVersionAndAnIdenticalScheduleDoesNot(): void
    {
        $id = $this->id($this->create('1200'));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Savings', 'schedule' => ['mode' => 'LINEAR'], 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::rows($this->decode()['versions']));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Savings', 'schedule' => ['mode' => 'CUSTOM', 'parts' => ['1200', ...array_fill(0, 11, '0')]], 'version' => 1]);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertCount(2, self::rows($updated['versions']));
        self::assertSame('1200', self::arr($updated['currentVersion'])['targetAmount']);
        self::assertSame('CUSTOM', self::arr(self::arr($updated['currentVersion'])['schedule'])['mode']);
    }

    public function testAStaleEditWritesNoPartialSchedule(): void
    {
        $id = $this->id($this->create('2000'));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Savings', 'targetAmount' => ['value' => '1000', 'assetCode' => 'EUR'], 'schedule' => ['mode' => 'LINEAR'], 'version' => 7]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_goal_version', $this->decode()['code']);
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goal_versions'));
        self::assertSame('12', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts'));
    }

    public function testEditingAnotherWorkspacesGoalScheduleAnswersNotFoundAndLeavesItIntact(): void
    {
        $this->insertForeignGoal();

        $this->requestJson('PUT', '/api/v1/goals/'.self::FOREIGN_GOAL, ['label' => 'Stolen', 'targetAmount' => ['value' => '1200', 'assetCode' => 'EUR'], 'schedule' => ['mode' => 'CUSTOM', 'parts' => ['1200', ...array_fill(0, 11, '0')]], 'version' => 1]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('12', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts WHERE workspace_id = ?', [WorkspaceFixture::OTHER_WORKSPACE]));
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [self::FOREIGN_GOAL]));
    }

    public function testThePreviewReturnsTheBackendLinearParts(): void
    {
        $this->client->request('GET', '/api/v1/goals/schedule-preview?target=100000.01&mode=LINEAR');

        self::assertResponseIsSuccessful();
        $preview = $this->decode();
        self::assertSame('LINEAR', $preview['mode']);
        $parts = self::rows($preview['parts']);
        self::assertSame(['monthIndex' => 1, 'amount' => '8333.33', 'cumulative' => '8333.33'], $parts[0]);
        self::assertSame(['monthIndex' => 12, 'amount' => '8333.38', 'cumulative' => '100000.01'], $parts[11]);
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts'));
    }

    public function testThePreviewRejectsImpossibleOrMalformedRequests(): void
    {
        $cases = ['target=0.10&mode=LINEAR' => 'linear_schedule_impossible', 'target=2000.001&mode=LINEAR' => 'schedule_precision_exceeded', 'target=2000&mode=CUSTOM' => 'invalid_schedule_preview', 'target=abc&mode=LINEAR' => 'invalid_schedule_preview', 'mode=LINEAR' => 'invalid_schedule_preview', 'target=-5&mode=LINEAR' => 'invalid_schedule_preview', 'target=0&mode=LINEAR' => 'invalid_schedule_preview'];
        foreach ($cases as $query => $code) {
            $this->client->request('GET', '/api/v1/goals/schedule-preview?'.$query);
            self::assertResponseStatusCodeSame(422, $query);
            self::assertSame($code, $this->decode()['code'], $query);
        }
    }

    public function testThePreviewRequiresAnAuthenticatedCaller(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/v1/goals/schedule-preview?target=2000&mode=LINEAR');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheDatabaseRefusesAScheduledVersionWithoutExactlyTwelveParts(): void
    {
        $this->insertForeignGoal(partCount: 12);

        $this->expectDatabaseRefusal(fn () => $this->connection->executeStatement('DELETE FROM budget_goal_schedule_parts WHERE month_index = 12'));
        $this->expectDatabaseRefusal(fn () => $this->connection->insert('budget_goal_schedule_parts', ['goal_version_id' => self::FOREIGN_VERSION, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'month_index' => 13, 'amount' => '0', 'amount_scale' => 2]));
        $this->expectDatabaseRefusal(fn () => $this->connection->executeStatement("UPDATE budget_goal_schedule_parts SET amount = '-1' WHERE month_index = 1"));
        $this->expectDatabaseRefusal(fn () => $this->insertVersion('00000000-0000-7000-8000-0000000000fa', 2, 11));
        self::assertSame('12', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts'));
    }

    private function expectDatabaseRefusal(\Closure $statement): void
    {
        $this->connection->beginTransaction();
        try {
            $statement();
            $this->connection->commit();
        } catch (DbalException) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->addToAssertionCount(1);

            return;
        }
        self::fail('The database must refuse this schedule change.');
    }

    /** @param list<string> $parameters */
    private function scalar(string $sql, array $parameters = []): string
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsScalar($value);

        return (string) $value;
    }

    /**
     * @param array<string, mixed>|string|null $schedule
     *
     * @return array<string, mixed>
     */
    private function create(string $target, array|string|null $schedule = null): array
    {
        $payload = ['label' => 'Savings', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'year' => 2026, 'targetAmount' => ['value' => $target, 'assetCode' => 'EUR']];
        if (null !== $schedule) {
            $payload['schedule'] = $schedule;
        }
        $this->requestJson('POST', '/api/v1/goals', $payload);

        return $this->decode();
    }

    private function insertForeignGoal(int $partCount = 12): void
    {
        $this->connection->insert('budget_goals', ['id' => self::FOREIGN_GOAL, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'label' => 'Foreign', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'state' => 'ACTIVE', 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID, 'closed_at' => null, 'version' => 1]);
        $this->connection->transactional(fn () => $this->insertVersion(self::FOREIGN_VERSION, 1, $partCount));
    }

    private function insertVersion(string $id, int $number, int $partCount): void
    {
        $this->connection->insert('budget_goal_versions', ['id' => $id, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'goal_id' => self::FOREIGN_GOAL, 'version_no' => $number, 'effective_from' => '2026-09', 'year' => 2026, 'target_amount' => '1200.00', 'target_scale' => 2, 'ratio' => null, 'ratio_scale' => null, 'schedule_mode' => 'LINEAR', 'reason' => null, 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID]);
        for ($month = 1; $month <= $partCount; ++$month) {
            $this->connection->insert('budget_goal_schedule_parts', ['goal_version_id' => $id, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'month_index' => $month, 'amount' => '100.00', 'amount_scale' => 2]);
        }
    }

    private function refreshCsrf(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    /** @param array<string, mixed> $body */
    private function requestJson(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        return self::arr(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
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

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_map(self::arr(...), $value));
    }
}
