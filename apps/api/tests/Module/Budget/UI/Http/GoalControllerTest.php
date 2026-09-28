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

final class GoalControllerTest extends WebTestCase
{
    private const string TODAY = '2026-09-14T09:12:04+00:00';

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

    public function testItCreatesEditsListsAndClosesAnAnnualGoalWithImmutableDefinitions(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode();
        self::assertSame('EUR', $created['assetCode']);
        self::assertSame('MINIMUM', $created['direction']);
        self::assertSame('12000.00', self::arr($created['currentVersion'])['targetAmount']);
        self::assertSame(1, $created['version']);
        $id = $this->id($created);

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Annual savings', 'targetAmount' => ['value' => '15000.125', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertSame(2, self::arr($updated['currentVersion'])['versionNo']);
        self::assertSame('15000.125', self::arr($updated['currentVersion'])['targetAmount']);
        self::assertSame('2026-09', self::arr($updated['currentVersion'])['effectiveFrom']);
        self::assertCount(2, self::items($updated['versions']));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Renamed savings', 'targetAmount' => null, 'ratio' => null, 'version' => 2]);
        self::assertResponseIsSuccessful();
        $renamed = $this->decode();
        self::assertSame(3, $renamed['version']);
        self::assertCount(2, self::items($renamed['versions']));

        $this->client->request('GET', '/api/v1/goals?year=2026&state=active');
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::items($this->decode()['items']));

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/close', ['version' => 3]);
        self::assertResponseIsSuccessful();
        self::assertSame('CLOSED', $this->decode()['state']);
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Cannot edit', 'targetAmount' => null, 'ratio' => null, 'version' => 4]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_closed', $this->decode()['code']);
        $this->client->request('GET', '/api/v1/goals?year=2026&state=closed');
        self::assertCount(1, self::items($this->decode()['items']));

        $events = $this->connection->fetchFirstColumn('SELECT event_type FROM audit_events WHERE workspace_id = ? AND entity_id = ? ORDER BY occurred_at', [WorkspaceFixture::OWN_WORKSPACE, $id]);
        self::assertSame(['goal.created', 'goal.updated', 'goal.updated', 'goal.closed'], $events);
        $encodedDiffs = self::strings($this->connection->fetchFirstColumn('SELECT after_json::text FROM audit_events WHERE workspace_id = ? AND entity_id = ?', [WorkspaceFixture::OWN_WORKSPACE, $id]));
        self::assertStringNotContainsString('12000', implode('', $encodedDiffs));
        self::assertStringNotContainsString('Annual savings', implode('', $encodedDiffs));
    }

    public function testCreatingAnAnnualGoalWithAMismatchedCurrencyIsRejected(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Annual savings', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'year' => 2026, 'targetAmount' => ['value' => '12000.00', 'assetCode' => 'USD']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unsupported_goal_currency', $this->decode()['code']);
    }

    public function testRollingGoalExposesAnExplicitMissingReferenceIncome(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Savings ratio', 'kind' => 'ROLLING_RATIO', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'ratio' => '0.30', 'startMonth' => '2026-09']);
        self::assertResponseStatusCodeSame(201);
        $goal = $this->decode();
        self::assertNull(self::arr($goal['referenceIncome'])['value']);
        self::assertSame(0, self::arr($goal['referenceIncome'])['usableMonths']);
        self::assertSame('NO_REFERENCE_INCOME', self::arr($goal['referenceIncome'])['reason']);
        self::assertNull(self::arr($goal['resolvedMonthlyTarget'])['value']);
    }

    public function testForeignUnknownAndArchivedCategoryReferencesShareTheSameErrorThenArchivingOnlyWarns(): void
    {
        $foreign = '00000000-0000-7000-8000-0000000000c9';
        $own = '00000000-0000-7000-8000-0000000000c1';
        $archived = '00000000-0000-7000-8000-0000000000c2';
        $this->insertCategory($foreign, WorkspaceFixture::OTHER_WORKSPACE);
        $this->insertCategory($own, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertCategory($archived, WorkspaceFixture::OWN_WORKSPACE);
        $this->connection->update('category_categories', ['archived_at' => '2026-09-10 12:00:00+00', 'version' => 2], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $archived]);
        $payload = $this->categoryPayload($foreign);
        $this->requestJson('POST', '/api/v1/goals', $payload);
        self::assertResponseStatusCodeSame(422);
        $foreignBody = (string) $this->client->getResponse()->getContent();
        $this->requestJson('POST', '/api/v1/goals', $this->categoryPayload('00000000-0000-7000-8000-0000000000c8'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame($foreignBody, (string) $this->client->getResponse()->getContent());
        $this->requestJson('POST', '/api/v1/goals', $this->categoryPayload($archived));
        self::assertResponseStatusCodeSame(422);
        self::assertSame($foreignBody, (string) $this->client->getResponse()->getContent());

        $this->requestJson('POST', '/api/v1/goals', $this->categoryPayload($own));
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());
        $this->connection->update('category_categories', ['archived_at' => '2026-09-20 12:00:00+00', 'version' => 2], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $own]);
        $this->client->request('GET', '/api/v1/goals/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame(['REFERENCE_ARCHIVED'], $this->decode()['warnings']);
    }

    public function testForeignGoalAnswersExactlyLikeUnknownAndIdentityIsImmutable(): void
    {
        $foreign = '00000000-0000-7000-8000-0000000000e9';
        $this->insertGoal($foreign, WorkspaceFixture::OTHER_WORKSPACE);
        $this->client->request('GET', '/api/v1/goals/'.$foreign);
        self::assertResponseStatusCodeSame(404);
        $body = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/api/v1/goals/00000000-0000-7000-8000-0000000000e8');
        self::assertSame($body, (string) $this->client->getResponse()->getContent());

        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        $goal = $this->decode();
        $this->requestJson('PUT', '/api/v1/goals/'.$this->id($goal), ['label' => 'Changed', 'targetAmount' => null, 'ratio' => null, 'metric' => 'BUDGET_SURPLUS', 'version' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('goal_identity_immutable', $this->decode()['code']);
    }

    public function testAccountReferenceMustBeActiveAtCreationAndWarnsAfterLaterArchiving(): void
    {
        $active = '00000000-0000-7000-8000-0000000000d1';
        $archived = '00000000-0000-7000-8000-0000000000d2';
        $this->insertAccount($active, null);
        $this->insertAccount($archived, '2026-09-10 12:00:00+00');
        $payload = fn (string $id): array => ['label' => 'Contributions', 'kind' => 'ANNUAL', 'metric' => 'ACCOUNT_CONTRIBUTIONS', 'scope' => ['type' => 'ACCOUNT', 'ref' => $id], 'year' => 2026, 'targetAmount' => ['value' => '5000.00', 'assetCode' => 'EUR']];

        $this->requestJson('POST', '/api/v1/goals', $payload($archived));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unknown_goal_reference', $this->decode()['code']);
        $this->requestJson('POST', '/api/v1/goals', $payload($active));
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());
        $this->connection->update('account_financial_accounts', ['archived_at' => '2026-09-20 12:00:00+00', 'version' => 2], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $active]);
        $this->client->request('GET', '/api/v1/goals/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame(['REFERENCE_ARCHIVED'], $this->decode()['warnings']);
    }

    public function testMutationsRequireCsrfAndStaleWritesConflict(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(403);
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        $id = $this->id($this->decode());
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Changed', 'targetAmount' => null, 'ratio' => null, 'version' => 99]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_goal_version', $this->decode()['code']);
    }

    public function testEveryForbiddenMetricScopePairIsRejectedBeforeReferenceLookup(): void
    {
        $metrics = [
            'NET_SAVINGS_TRANSFERS' => ['ANNUAL:WORKSPACE', 'ROLLING_RATIO:WORKSPACE'],
            'BUDGET_SURPLUS' => ['ANNUAL:WORKSPACE'],
            'CATEGORY_SPENDING' => ['ANNUAL:CATEGORY', 'ROLLING_RATIO:CATEGORY'],
            'AXIS_SPENDING' => ['ANNUAL:AXIS', 'ROLLING_RATIO:AXIS'],
            'ACCOUNT_CONTRIBUTIONS' => ['ANNUAL:ACCOUNT'],
        ];
        foreach (['ANNUAL', 'ROLLING_RATIO'] as $kind) {
            foreach (array_keys($metrics) as $metric) {
                foreach (['WORKSPACE', 'CATEGORY', 'AXIS', 'ACCOUNT'] as $scope) {
                    if (in_array($kind.':'.$scope, $metrics[$metric], true)) {
                        continue;
                    }
                    $payload = ['label' => 'Invalid pair', 'kind' => $kind, 'metric' => $metric, 'scope' => ['type' => $scope, 'ref' => 'WORKSPACE' === $scope ? null : '00000000-0000-7000-8000-0000000000c1']];
                    if ('ANNUAL' === $kind) {
                        $payload += ['year' => 2026, 'targetAmount' => ['value' => '100.00', 'assetCode' => 'EUR']];
                    } else {
                        $payload += ['ratio' => '0.30', 'startMonth' => '2026-09'];
                    }
                    $this->requestJson('POST', '/api/v1/goals', $payload);
                    self::assertResponseStatusCodeSame(422);
                    self::assertSame('invalid_goal_metric_scope', $this->decode()['code']);
                }
            }
        }
    }

    public function testActiveGoalAndDefinitionVersionLimitsAreEnforced(): void
    {
        for ($index = 1; $index <= 50; ++$index) {
            $this->insertGoal(sprintf('00000000-0000-7000-8000-%012d', $index), WorkspaceFixture::OWN_WORKSPACE, sprintf('00000000-0000-7000-9000-%012d', $index));
        }
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_limit_reached', $this->decode()['code']);

        $this->connection->executeStatement('DELETE FROM budget_goal_versions WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]);
        $this->connection->executeStatement('DELETE FROM budget_goals WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]);
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        $goal = $this->decode();
        $id = $this->id($goal);
        for ($number = 2; $number <= 50; ++$number) {
            $this->insertVersion(sprintf('00000000-0000-7000-9000-%012d', $number), WorkspaceFixture::OWN_WORKSPACE, $id, $number);
        }
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Annual savings', 'targetAmount' => ['value' => '20000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_version_limit', $this->decode()['code']);
    }

    public function testEditingAnAnnualGoalNeverMovesItsVersionBeforeTheGoalsOwnFirstMonth(): void
    {
        // The goal's own first version starts 2027-01. Edited "now" (frozen at 2026-09), the
        // new version must still take effect no earlier than the goal's own start, never at
        // the edit's wall-clock month: every 2027 month must see the edited target, not v1.
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Future savings', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'year' => 2027, 'targetAmount' => ['value' => '12000.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $goal = $this->decode();
        self::assertSame('2027-01', self::arr($goal['currentVersion'])['effectiveFrom']);
        $id = $this->id($goal);

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Future savings', 'targetAmount' => ['value' => '15000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertSame(2, self::arr($updated['currentVersion'])['versionNo']);
        self::assertSame('2027-01', self::arr($updated['currentVersion'])['effectiveFrom']);
    }

    public function testEditingARollingGoalThatStartsInTheFutureKeepsItOutOfTheCurrentYearsListing(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Future ratio', 'kind' => 'ROLLING_RATIO', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'ratio' => '0.20', 'startMonth' => '2027-01']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        // Edited now (2026-09), the goal must not appear to start four months earlier than
        // its owner chose: the new version still takes effect no earlier than 2027-01.
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Future ratio', 'ratio' => '0.25', 'targetAmount' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertSame('2027-01', self::arr($updated['currentVersion'])['effectiveFrom']);

        $this->client->request('GET', '/api/v1/goals?year=2026&state=active');
        self::assertResponseIsSuccessful();
        self::assertCount(0, self::items($this->decode()['items']));

        $this->client->request('GET', '/api/v1/goals?year=2027&state=active');
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::items($this->decode()['items']));
    }

    public function testEditingTheTargetOfAnAnnualGoalWhoseYearHasElapsedIsRejected(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Past savings', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'year' => 2025, 'targetAmount' => ['value' => '9000.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Past savings', 'targetAmount' => ['value' => '9500.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('goal_year_elapsed', $this->decode()['code']);

        // Renaming the same past goal, without changing its value, is still allowed.
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Past savings renamed', 'targetAmount' => ['value' => '9000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode()['version']);
        self::assertSame(1, self::arr($this->decode()['currentVersion'])['versionNo']);
    }

    public function testRenamingTwiceInARowDoesNotConsumeAVersion(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Renamed once', 'targetAmount' => ['value' => '12000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $first = $this->decode();
        self::assertSame(1, self::arr($first['currentVersion'])['versionNo']);
        self::assertCount(1, self::items($first['versions']));
        self::assertSame(2, $first['version']);

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Renamed twice', 'targetAmount' => ['value' => '12000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 2]);
        self::assertResponseIsSuccessful();
        $second = $this->decode();
        self::assertSame(1, self::arr($second['currentVersion'])['versionNo']);
        self::assertCount(1, self::items($second['versions']));
        self::assertSame(3, $second['version']);
        self::assertSame('Renamed twice', $second['label']);
    }

    public function testTheEffectiveMonthFollowsTheFrozenClockNotTheRealWallClockDate(): void
    {
        // Locks in that version resolution is date-driven through the clock, not tied to
        // whatever month the real calendar happens to show when this test runs.
        $this->clock->modify('2026-08-05T00:00:00+00:00');
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Annual savings', 'targetAmount' => ['value' => '13000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertSame(2, self::arr($updated['currentVersion'])['versionNo']);
        self::assertSame('2026-08', self::arr($updated['currentVersion'])['effectiveFrom']);
    }

    public function testEditingAndClosingAnotherWorkspacesGoalAnswersNotFound(): void
    {
        $foreign = '00000000-0000-7000-8000-0000000000e9';
        $this->insertGoal($foreign, WorkspaceFixture::OTHER_WORKSPACE);

        $this->requestJson('PUT', '/api/v1/goals/'.$foreign, ['label' => 'Changed', 'targetAmount' => null, 'ratio' => null, 'version' => 1]);
        self::assertResponseStatusCodeSame(404);

        $this->requestJson('POST', '/api/v1/goals/'.$foreign.'/close', ['version' => 1]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTargetAmountAcceptsTheTwentySixIntegerDigitBoundaryAndRejectsOneMore(): void
    {
        $atLimit = str_repeat('9', 26);
        $overLimit = str_repeat('9', 27);

        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Boundary', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'year' => 2026, 'targetAmount' => ['value' => $overLimit, 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(422);

        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Boundary', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'year' => 2026, 'targetAmount' => ['value' => $atLimit, 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($atLimit, self::arr($this->decode()['currentVersion'])['targetAmount']);
    }

    public function testRatioAcceptsOneAndTheSmallestEightDecimalStepAndRejectsOneDecimalTooMany(): void
    {
        $payload = fn (string $ratio): array => ['label' => 'Ratio boundary', 'kind' => 'ROLLING_RATIO', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'ratio' => $ratio, 'startMonth' => '2026-09'];

        $this->requestJson('POST', '/api/v1/goals', $payload('1'));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('1', self::arr($this->decode()['currentVersion'])['ratio']);

        $this->requestJson('POST', '/api/v1/goals', $payload('0.00000001'));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('0.00000001', self::arr($this->decode()['currentVersion'])['ratio']);

        $this->requestJson('POST', '/api/v1/goals', $payload('0.000000001'));
        self::assertResponseStatusCodeSame(422);
    }

    public function testSavingWithoutAnyChangeReturnsTheGoalUnchanged(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Annual savings', 'targetAmount' => ['value' => '12000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $unchanged = $this->decode();
        self::assertSame(1, $unchanged['version']);
        self::assertSame(1, self::arr($unchanged['currentVersion'])['versionNo']);
        self::assertCount(1, self::items($unchanged['versions']));

        $events = $this->connection->fetchFirstColumn('SELECT event_type FROM audit_events WHERE workspace_id = ? AND entity_id = ? ORDER BY occurred_at', [WorkspaceFixture::OWN_WORKSPACE, $id]);
        self::assertSame(['goal.created'], $events);
    }

    public function testRenamingWithANumericallyEqualAmountDoesNotConsumeAVersion(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Renamed', 'targetAmount' => ['value' => '12000', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $renamed = $this->decode();
        self::assertSame(2, $renamed['version']);
        self::assertSame(1, self::arr($renamed['currentVersion'])['versionNo']);
        self::assertCount(1, self::items($renamed['versions']));
    }

    /** @return array<string, mixed> */
    private function annualPayload(): array
    {
        return ['label' => 'Annual savings', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'year' => 2026, 'targetAmount' => ['value' => '12000.00', 'assetCode' => 'EUR']];
    }

    /** @return array<string, mixed> */
    private function categoryPayload(string $id): array
    {
        return ['label' => 'Leisure cap', 'kind' => 'ANNUAL', 'metric' => 'CATEGORY_SPENDING', 'scope' => ['type' => 'CATEGORY', 'ref' => $id], 'year' => 2026, 'targetAmount' => ['value' => '1200.00', 'assetCode' => 'EUR']];
    }

    private function insertCategory(string $id, string $workspaceId): void
    {
        $this->connection->insert('category_categories', ['id' => $id, 'workspace_id' => $workspaceId, 'type' => 'EXPENSE', 'label' => 'Leisure '.substr($id, -2), 'parent_id' => null, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1, 'version' => 1, 'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00', 'archived_at' => null]);
    }

    private function insertGoal(string $id, string $workspace, string $versionId = '00000000-0000-7000-8000-0000000000f9'): void
    {
        $this->connection->insert('budget_goals', ['id' => $id, 'workspace_id' => $workspace, 'label' => 'Foreign', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'state' => 'ACTIVE', 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID, 'closed_at' => null, 'version' => 1]);
        $this->insertVersion($versionId, $workspace, $id, 1);
    }

    private function insertAccount(string $id, ?string $archivedAt): void
    {
        $this->connection->insert('account_financial_accounts', ['id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Account '.substr($id, -2), 'asset_code' => 'EUR', 'kind' => 'SAVINGS', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true, 'include_in_emergency_fund' => true, 'opened_on' => '2026-01-01', 'closed_on' => null, 'version' => 1, 'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00', 'used_at' => null, 'archived_at' => $archivedAt]);
    }

    private function insertVersion(string $id, string $workspace, string $goalId, int $number): void
    {
        $this->connection->insert('budget_goal_versions', ['id' => $id, 'workspace_id' => $workspace, 'goal_id' => $goalId, 'version_no' => $number, 'effective_from' => '2026-09', 'year' => 2026, 'target_amount' => '100.00', 'target_scale' => 2, 'ratio' => null, 'ratio_scale' => null, 'schedule_mode' => null, 'reason' => null, 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID]);
    }

    /** @param array<string, mixed> $body */
    private function requestJson(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
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

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_map(static function (mixed $value): string {
            self::assertIsString($value);

            return $value;
        }, $values);
    }
}
