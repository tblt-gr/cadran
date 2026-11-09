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

    public function testStockGoalsReadDatedValuesAndStartWithoutDependingOnReportingMonths(): void
    {
        $account = '00000000-0000-7000-8000-0000000000d1';
        $this->insertAccount($account, null);
        $this->connection->update('account_financial_accounts', ['opened_on' => '2025-01-01'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $account]);
        $this->stockSnapshot($account, '2025-12-31', '10000.00');
        $this->stockSnapshot($account, '2026-03-31', '12000.00');
        $excluded = '00000000-0000-7000-8000-0000000000d4';
        $this->insertAccount($excluded, null);
        $this->connection->update('account_financial_accounts', ['closed_on' => '2026-04-14'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $excluded]);
        $this->stockSnapshot($excluded, '2026-04-14', '999.00');
        $this->clock->modify('2026-04-15T12:00:00+00:00');
        $this->refreshCsrf();
        $this->client->request('GET', '/api/v1/net-worth?asOf=2026-04-15');
        self::assertResponseIsSuccessful();
        $contributions = $this->decode()['contributions'];
        self::assertIsArray($contributions);
        $closedContribution = null;
        foreach ($contributions as $contribution) {
            $contribution = self::arr($contribution);
            if ($excluded === $contribution['accountId']) {
                $closedContribution = $contribution;
            }
        }
        self::assertNotNull($closedContribution);
        self::assertFalse($closedContribution['eligible']);
        self::assertSame('2026-04-14', $closedContribution['valuedOn']);
        $payload = ['label' => 'Net worth', 'periodicity' => 'YEARLY', 'metric' => 'NET_WORTH', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '20000.00', 'assetCode' => 'EUR']];
        $this->requestJson('POST', '/api/v1/goals', $payload);
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/api/v1/goals/evaluations?from=2026-01&to=2026-12&granularity=YEAR');
        self::assertResponseIsSuccessful();
        $item = $this->stockItem('years');
        $item = self::arr($item);
        self::assertSame('IN_PROGRESS', $item['status']);
        self::assertSame('BEHIND', self::arr($item['pace'])['status']);
        $expected = self::arr($item['pace'])['expected'];
        self::assertIsString($expected);
        self::assertSame(0, \App\Module\Foundation\Domain\ExactDecimal::compareForResponse('12500', $expected));
        self::assertSame('10000.00', self::arr($item['start'])['value']);
        self::assertSame('2026-03-31', $item['valuationDate']);
        self::assertSame('/accounts', $item['explanationUrl']);
        $this->stockSnapshot($account, '2026-12-31', '19500.00');
        $this->clock->modify('2026-12-31T12:00:00+00:00');
        $this->refreshCsrf();
        $this->client->request('GET', '/api/v1/goals/evaluations?from=2026-01&to=2026-12&granularity=YEAR');
        self::assertResponseIsSuccessful();
        $item = self::arr($this->stockItem('years'));
        self::assertSame('MISSED', $item['status']);
        $this->connection->update('account_balance_snapshots', ['amount_value' => '20100.00', 'amount_literal' => '20100.00'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => $account, 'as_of' => '2026-12-31']);
        $url = '/api/v1/goals/evaluations?from=2026-01&to=2026-12&granularity=YEAR';
        $this->client->request('GET', $url);
        self::assertSame('MET', $this->stockItem('years')['status']);
        $this->connection->update('account_balance_snapshots', ['amount_value' => '19999.99', 'amount_literal' => '19999.99'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => $account, 'as_of' => '2026-12-31']);
        $this->client->request('GET', $url);
        self::assertSame('MISSED', $this->stockItem('years')['status']);
        $this->connection->update('account_balance_snapshots', ['amount_value' => '19500.00', 'amount_literal' => '19500.00'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => $account, 'as_of' => '2026-12-31']);
        $payload['tolerance'] = '500.00';
        $payload['label'] = 'Tolerant net worth';
        $this->requestJson('POST', '/api/v1/goals', $payload);
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', $url);
        $item = $this->stockItem('years');
        self::assertSame('MET', $item['status']);
        self::assertTrue($item['withinTolerance']);
        $foreignAssetAccount = '00000000-0000-7000-8000-0000000000d3';
        $this->insertAccount($foreignAssetAccount, null);
        $this->connection->update('account_financial_accounts', ['asset_code' => 'USD'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $foreignAssetAccount]);
        $this->stockSnapshot($foreignAssetAccount, '2026-12-31', '1.00');
        $this->connection->update('account_balance_snapshots', ['amount_asset' => 'USD'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => $foreignAssetAccount]);
        $this->client->request('GET', $url);
        $item = $this->stockItem('years');
        self::assertSame('NOT_CALCULABLE', $item['status']);
        self::assertSame('MIXED_ASSETS', $item['reason']);
        self::assertNull($item['achieved']);
    }

    public function testAYearlyStockTargetNeedsNoTwelvePartSchedule(): void
    {
        $payload = ['label' => 'Tiny stock', 'periodicity' => 'YEARLY', 'metric' => 'NET_WORTH', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '0.06', 'assetCode' => 'EUR']];
        $this->requestJson('POST', '/api/v1/goals', $payload);
        self::assertResponseStatusCodeSame(201);
        $goal = $this->decode();
        $id = $this->id($goal);
        self::assertNull(self::arr($goal['currentVersion'])['schedule']);
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Tiny stock', 'version' => 1, 'targetAmount' => ['value' => '0.07', 'assetCode' => 'EUR']]);
        self::assertResponseIsSuccessful();
        $goal = $this->decode();
        self::assertSame('0.07', self::arr($goal['currentVersion'])['targetAmount']);
        self::assertNull(self::arr($goal['currentVersion'])['schedule']);
    }

    public function testAccountStockHasNoDataAndRejectsArchivedAndForeignReferences(): void
    {
        $account = '00000000-0000-7000-8000-0000000000d1';
        $this->insertAccount($account, null);
        $payload = ['label' => 'Balance ceiling', 'periodicity' => 'MONTHLY', 'metric' => 'ACCOUNT_BALANCE', 'direction' => 'CEILING', 'scope' => ['type' => 'ACCOUNT', 'ref' => $account], 'startPeriod' => '2026-09', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '1000.00', 'assetCode' => 'EUR']];
        $this->requestJson('POST', '/api/v1/goals', $payload);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('CEILING', $this->decode()['direction']);
        $url = '/api/v1/goals/evaluations?from=2026-09&to=2026-09&granularity=MONTH';
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $item = $this->stockItem('months');
        self::assertSame('NOT_CALCULABLE', $item['status']);
        self::assertSame('NO_DATA', $item['reason']);
        self::assertNull($item['achieved']);
        $this->stockSnapshot($account, '2026-09-01', '1000.01');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $item = $this->stockItem('months');
        self::assertSame('MISSED', $item['status']);
        $this->connection->update('account_financial_accounts', ['archived_at' => '2026-09-10 12:00:00+00'], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $account]);
        $this->client->request('GET', $url);
        $item = $this->stockItem('months');
        self::assertSame('NOT_CALCULABLE', $item['status']);
        self::assertSame('REFERENCE_ARCHIVED', $item['reason']);
        self::assertNull($item['achieved']);
        $this->requestJson('POST', '/api/v1/goals', $payload);
        self::assertResponseStatusCodeSame(422);
        $foreign = '00000000-0000-7000-8000-0000000000d2';
        $this->insertAccount($foreign, null);
        $this->connection->update('account_financial_accounts', ['workspace_id' => WorkspaceFixture::OTHER_WORKSPACE], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $foreign]);
        $payload['scope']['ref'] = $foreign;
        $this->requestJson('POST', '/api/v1/goals', $payload);
        self::assertResponseStatusCodeSame(422);
    }

    /** @return array<string, mixed> */
    private function stockItem(string $key): array
    {
        $periods = $this->decode()[$key];
        self::assertIsArray($periods);
        $period = self::arr($periods[0]);
        $items = $period['items'];
        self::assertIsArray($items);

        return self::arr($items[0]);
    }

    private function stockSnapshot(string $account, string $day, string $amount): void
    {
        $this->connection->insert('account_balance_snapshots', ['id' => substr(sha1($account.$day), 0, 8).'-0000-7000-8000-000000000000', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => $account, 'as_of' => $day, 'amount_value' => $amount, 'amount_literal' => $amount, 'amount_asset' => 'EUR', 'source' => 'MANUAL', 'reconciliation_status' => 'UNRECONCILED', 'version' => 1, 'recorded_at' => $day.' 12:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID]);
    }

    public function testItCreatesEditsAndListsAYearlyGoalWithImmutableDefinitions(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode();
        self::assertSame('EUR', $created['assetCode']);
        self::assertSame('MINIMUM', $created['direction']);
        self::assertSame('YEARLY', $created['periodicity']);
        self::assertSame('2026', $created['startPeriod']);
        self::assertSame('2026', $created['endPeriod']);
        self::assertSame('AMOUNT', self::arr($created['currentVersion'])['valueType']);
        self::assertSame('12000.00', self::arr($created['currentVersion'])['targetAmount']);
        self::assertSame(1, $created['version']);
        $id = $this->id($created);

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Annual savings', 'targetAmount' => ['value' => '15000.12', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertSame(2, self::arr($updated['currentVersion'])['versionNo']);
        self::assertSame('15000.12', self::arr($updated['currentVersion'])['targetAmount']);
        self::assertSame('2026-09', self::arr($updated['currentVersion'])['effectiveFrom']);
        self::assertCount(2, self::items($updated['versions']));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Renamed savings', 'targetAmount' => null, 'ratio' => null, 'version' => 2]);
        self::assertResponseIsSuccessful();
        $renamed = $this->decode();
        self::assertSame(3, $renamed['version']);
        self::assertCount(2, self::items($renamed['versions']));

        $this->client->request('GET', '/api/v1/goals');
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::items($this->decode()['items']));

        $events = $this->connection->fetchFirstColumn('SELECT event_type FROM audit_events WHERE workspace_id = ? AND entity_id = ? ORDER BY occurred_at', [WorkspaceFixture::OWN_WORKSPACE, $id]);
        self::assertSame(['goal.created', 'goal.updated', 'goal.updated'], $events);
        $encodedDiffs = self::strings($this->connection->fetchFirstColumn('SELECT after_json::text FROM audit_events WHERE workspace_id = ? AND entity_id = ?', [WorkspaceFixture::OWN_WORKSPACE, $id]));
        self::assertStringNotContainsString('12000', implode('', $encodedDiffs));
        self::assertStringNotContainsString('Annual savings', implode('', $encodedDiffs));
    }

    public function testCreatingAnAnnualGoalWithAMismatchedCurrencyIsRejected(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Annual savings', 'periodicity' => 'YEARLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026', 'endPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '12000.00', 'assetCode' => 'USD']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unsupported_goal_currency', $this->decode()['code']);
    }

    public function testRollingGoalExposesAnExplicitMissingReferenceIncome(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Savings ratio', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'ratio' => '0.30', 'startPeriod' => '2026-09', 'endPeriod' => null, 'valueType' => 'ROLLING_INCOME_SHARE']);
        self::assertResponseStatusCodeSame(201);
        $goal = $this->decode();
        self::assertNull(self::arr($goal['referenceIncome'])['value']);
        self::assertSame(0, self::arr($goal['referenceIncome'])['usableMonths']);
        self::assertSame('NO_REFERENCE_INCOME', self::arr($goal['referenceIncome'])['reason']);
        self::assertNull(self::arr($goal['resolvedMonthlyTarget'])['value']);
    }

    public function testStatusEndpointAndListExposeAnExplicitNonCalculableAnnualStatus(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?month=2026-09');
        self::assertResponseIsSuccessful();
        $status = $this->decode();
        self::assertSame($id, $status['goalId']);
        self::assertSame('2026-09', $status['month']);
        self::assertSame('9000.00', $status['expected']);
        self::assertNull($status['achieved']);
        self::assertSame('NOT_CALCULABLE', $status['status']);
        self::assertSame('MISSING_MONTH_VALUE', $status['reason']);
        self::assertSame('PARTIAL', $status['quality']);
        self::assertCount(9, self::items($status['months']));

        $this->client->request('GET', '/api/v1/goals?period=2026-09');
        self::assertResponseIsSuccessful();
        $summary = self::arr(self::items($this->decode()['items'])[0]);
        $summaryStatus = self::arr($summary['status']);
        self::assertSame('NOT_CALCULABLE', $summaryStatus['status']);
        self::assertArrayNotHasKey('months', $summaryStatus);
    }

    public function testStatusMonthDefaultsToTheCurrentMonthOrDecemberOfAPastYear(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        $id = $this->id($this->decode());

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status');
        self::assertResponseIsSuccessful();
        self::assertSame('2026-09', $this->decode()['month']);

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?year=2026');
        self::assertResponseIsSuccessful();
        self::assertSame('2026-09', $this->decode()['month']);

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?year=2025');
        self::assertResponseIsSuccessful();
        $status = $this->decode();
        self::assertSame('2025-12', $status['month']);
        self::assertSame('GOAL_NOT_STARTED', $status['reason']);
        self::assertNull($status['gap']);
    }

    public function testStatusRejectsMalformedMonthsAndMonthsOutsideTheRequestedYear(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        $id = $this->id($this->decode());

        foreach (['2026-13', '2026-9', 'september', ''] as $month) {
            $this->client->request('GET', '/api/v1/goals/'.$id.'/status?month='.$month);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('invalid_goal_month', $this->decode()['code']);
        }
        foreach (['26', '1899', '2032'] as $year) {
            $this->client->request('GET', '/api/v1/goals/'.$id.'/status?year='.$year);
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?year=2025&month=2026-09');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_goal_query', $this->decode()['code']);

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?year=2027');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('future_goal_month', $this->decode()['code']);
    }

    public function testListReadsAFutureMonthAsNotCalculableAndRejectsMalformedPeriods(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());

        $this->client->request('GET', '/api/v1/goals?period=2026-10');
        self::assertResponseIsSuccessful();
        $status = self::arr(self::arr(self::items($this->decode()['items'])[0])['status']);
        self::assertSame('2026-10', $status['month']);
        self::assertSame('FUTURE_MONTH', $status['reason']);

        foreach (['2026-13', '26', '2026-1', 'abc', '1899', '2032'] as $period) {
            $this->client->request('GET', '/api/v1/goals?period='.$period);
            self::assertResponseStatusCodeSame(400, $period);
        }
        foreach (['periodicity=WEEKLY', 'current=maybe', 'year=2026', 'state=closed'] as $query) {
            $this->client->request('GET', '/api/v1/goals?'.$query);
            self::assertTrue(in_array($this->client->getResponse()->getStatusCode(), [200, 400], true), $query);
        }
    }

    public function testFutureAndForeignGoalStatusAreRejectedWithoutLeakingTheGoal(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        $id = $this->id($this->decode());
        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?month=2026-10');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('future_goal_month', $this->decode()['code']);

        $foreign = '00000000-0000-7000-8000-0000000000e7';
        $this->insertGoal($foreign, WorkspaceFixture::OTHER_WORKSPACE, '00000000-0000-7000-8000-0000000000f7');
        $this->client->request('GET', '/api/v1/goals/'.$foreign.'/status?month=2026-09');
        self::assertResponseStatusCodeSame(404);
        $body = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/api/v1/goals/00000000-0000-7000-8000-0000000000e8/status?month=2026-09');
        self::assertSame($body, (string) $this->client->getResponse()->getContent());
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
        $payload = fn (string $id): array => ['label' => 'Contributions', 'periodicity' => 'YEARLY', 'metric' => 'ACCOUNT_CONTRIBUTIONS', 'scope' => ['type' => 'ACCOUNT', 'ref' => $id], 'startPeriod' => '2026', 'endPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '5000.00', 'assetCode' => 'EUR']];

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
        $payload = ['label' => 'Changed', 'targetAmount' => ['value' => '13000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1];
        $this->requestJson('PUT', '/api/v1/goals/'.$id, $payload);
        self::assertResponseIsSuccessful();
        $this->requestJson('PUT', '/api/v1/goals/'.$id, $payload);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_goal_version', $this->decode()['code']);
        self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));
    }

    public function testEveryForbiddenMetricScopePairIsRejectedBeforeReferenceLookup(): void
    {
        $allowed = [
            'NET_SAVINGS_TRANSFERS' => 'WORKSPACE', 'BUDGET_SURPLUS' => 'WORKSPACE', 'SAVINGS_RATE' => 'WORKSPACE', 'NET_SAVINGS_RATE' => 'WORKSPACE',
            'CATEGORY_SPENDING' => 'CATEGORY', 'AXIS_SPENDING' => 'AXIS', 'ACCOUNT_CONTRIBUTIONS' => 'ACCOUNT',
        ];
        foreach (['YEARLY' => '2026', 'MONTHLY' => '2026-09'] as $periodicity => $start) {
            foreach ($allowed as $metric => $allowedScope) {
                foreach (['WORKSPACE', 'CATEGORY', 'AXIS', 'ACCOUNT'] as $scope) {
                    if ($allowedScope === $scope) {
                        continue;
                    }
                    $this->requestJson('POST', '/api/v1/goals', [
                        'label' => 'Invalid pair', 'periodicity' => $periodicity, 'metric' => $metric, 'scope' => ['type' => $scope, 'ref' => 'WORKSPACE' === $scope ? null : '00000000-0000-7000-8000-0000000000c1'],
                        'startPeriod' => $start, 'endPeriod' => null, 'valueType' => 'RATE', 'ratio' => '0.30',
                    ]);
                    self::assertResponseStatusCodeSame(422);
                    self::assertSame('invalid_goal_metric_scope', $this->decode()['code']);
                }
            }
        }
    }

    public function testEveryRefusedValueTypeAnswersBadRequestAndAcceptedOnesCreate(): void
    {
        $send = function (string $periodicity, string $metric, string $scopeType, ?string $ref, string $valueType, bool $amount): void {
            $this->requestJson('POST', '/api/v1/goals', [
                'label' => 'Matrix', 'periodicity' => $periodicity, 'metric' => $metric, 'scope' => ['type' => $scopeType, 'ref' => $ref],
                'startPeriod' => 'YEARLY' === $periodicity ? '2026' : '2026-11', 'endPeriod' => null, 'valueType' => $valueType,
                ...($amount ? ['targetAmount' => ['value' => '400.00', 'assetCode' => 'EUR']] : ['ratio' => '0.2']),
            ]);
        };
        foreach ([['MONTHLY', 'SAVINGS_RATE', 'AMOUNT', true], ['YEARLY', 'NET_SAVINGS_RATE', 'INCOME_SHARE', false], ['YEARLY', 'NET_SAVINGS_TRANSFERS', 'ROLLING_INCOME_SHARE', false], ['MONTHLY', 'BUDGET_SURPLUS', 'RATE', false], ['MONTHLY', 'BUDGET_SURPLUS', 'NOPE', false]] as [$periodicity, $metric, $valueType, $amount]) {
            $send($periodicity, $metric, 'WORKSPACE', null, $valueType, $amount);
            self::assertResponseStatusCodeSame(400, $metric.' '.$valueType);
            self::assertSame('invalid_goal_value_type', $this->decode()['code']);
        }
        foreach ([['MONTHLY', 'SAVINGS_RATE', 'RATE', false], ['YEARLY', 'NET_SAVINGS_RATE', 'RATE', false], ['MONTHLY', 'BUDGET_SURPLUS', 'ROLLING_INCOME_SHARE', false], ['YEARLY', 'BUDGET_SURPLUS', 'INCOME_SHARE', false], ['MONTHLY', 'NET_SAVINGS_TRANSFERS', 'AMOUNT', true]] as [$periodicity, $metric, $valueType, $amount]) {
            $send($periodicity, $metric, 'WORKSPACE', null, $valueType, $amount);
            self::assertResponseStatusCodeSame(201, $metric.' '.$valueType);
        }
    }

    public function testAMonthlyAmountGoalWithoutEndIsCreatedAndListedForALaterPeriod(): void
    {
        $category = '00000000-0000-7000-8000-0000000000c1';
        $this->insertCategory($category, WorkspaceFixture::OWN_WORKSPACE);

        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Courses', 'periodicity' => 'MONTHLY', 'metric' => 'CATEGORY_SPENDING', 'scope' => ['type' => 'CATEGORY', 'ref' => $category], 'startPeriod' => '2026-11', 'endPeriod' => null, 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '400.00', 'assetCode' => 'EUR']]);

        self::assertResponseStatusCodeSame(201);
        $goal = $this->decode();
        self::assertNull($goal['endPeriod']);
        self::assertSame('2026-11', $goal['startPeriod']);
        self::assertSame('CEILING', $goal['direction']);
        self::assertNull(self::arr($goal['currentVersion'])['schedule']);
        self::assertSame('2026-11', self::arr($goal['currentVersion'])['effectiveFrom']);
        $this->client->request('GET', '/api/v1/goals?period=2027-03');
        self::assertCount(1, self::items($this->decode()['items']));
        $this->client->request('GET', '/api/v1/goals?period=2026-10');
        self::assertCount(0, self::items($this->decode()['items']));
        $this->client->request('GET', '/api/v1/goals?periodicity=YEARLY');
        self::assertCount(0, self::items($this->decode()['items']));
    }

    public function testTheStatusOfAMonthlyGoalIsReadMonthByMonthWithoutCumulatingTheTargets(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Savings floor', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-07', 'endPeriod' => null, 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '100.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?month=2026-09');

        self::assertResponseIsSuccessful();
        $status = $this->decode();
        self::assertSame('MONTHLY', $status['periodicity']);
        self::assertSame('AMOUNT', $status['valueType']);
        self::assertSame('100.00', $status['target']);
        self::assertSame(['2026-07', '2026-08', '2026-09'], array_column(self::items($status['months']), 'month'));
        self::assertSame(['100.00', '100.00', '100.00'], array_column(self::items($status['months']), 'expectedPart'));
        self::assertSame('NOT_CALCULABLE', $status['status']);

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?month=2026-06');
        self::assertSame('GOAL_NOT_STARTED', $this->decode()['reason']);
    }

    public function testPeriodsOutsideTheirFormatOrOrderAnswerBadRequest(): void
    {
        $base = ['label' => 'Periods', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '10.00', 'assetCode' => 'EUR']];
        foreach ([['2026-11', '2026-10'], ['2026', null], ['2026-13', null], ['2026-11', '2026'], ['1899-12', null], ['2032-01', null]] as [$start, $end]) {
            $this->requestJson('POST', '/api/v1/goals', [...$base, 'startPeriod' => $start, 'endPeriod' => $end]);
            self::assertResponseStatusCodeSame(400, $start.' '.$end);
            self::assertSame('invalid_goal_period', $this->decode()['code']);
        }
        $this->requestJson('POST', '/api/v1/goals', [...$base, 'periodicity' => 'YEARLY', 'startPeriod' => '2027', 'endPeriod' => '2026']);
        self::assertResponseStatusCodeSame(400);
        $this->requestJson('POST', '/api/v1/goals', [...$base, 'startPeriod' => '2026-11', 'endPeriod' => '2026-11']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-11', $this->decode()['endPeriod']);
    }

    public function testAVersionEffectiveInAClosedMonthIsRefusedAtCreationAndOnUpdate(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Monthly', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-09', 'endPeriod' => null, 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '100.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());
        $this->closeMonth(2026, 8, '00000000-0000-7000-8000-0000000000a1');
        $this->closeMonth(2026, 9, '00000000-0000-7000-8000-0000000000a2');

        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Closed start', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-08', 'endPeriod' => null, 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '100.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_closed_period', $this->decode()['code']);

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Monthly', 'targetAmount' => ['value' => '120.00', 'assetCode' => 'EUR'], 'version' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_closed_period', $this->decode()['code']);
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));

        // A rename carries no definition and stays allowed in a closed month.
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Monthly renamed', 'version' => 1]);
        self::assertResponseIsSuccessful();
    }

    public function testAYearlyGoalStartsAtTheFirstOpenMonthOfItsYearAndIsRefusedWhenEveryMonthIsClosed(): void
    {
        $this->closeMonth(2026, 1, '00000000-0000-7000-8000-0000000000a1');

        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());

        self::assertResponseStatusCodeSame(201);
        $goal = $this->decode();
        self::assertSame('2026', $goal['startPeriod']);
        self::assertSame('2026-02', self::arr($goal['currentVersion'])['effectiveFrom']);

        for ($month = 2; $month <= 12; ++$month) {
            $this->closeMonth(2026, $month, sprintf('00000000-0000-7000-8000-%012d', 100 + $month));
        }
        $this->requestJson('POST', '/api/v1/goals', [...$this->annualPayload(), 'label' => 'Second']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_closed_period', $this->decode()['code']);
    }

    public function testAnAmountForAYearlyScheduleIsRefusedOnAMonthlyGoalAndRateGoalsCarryNone(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Monthly schedule', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-09', 'endPeriod' => null, 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '100.00', 'assetCode' => 'EUR'], 'schedule' => ['mode' => 'LINEAR']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_goal_schedule', $this->decode()['code']);
    }

    public function testAValueTypeCanChangeOnAnUpdateAndIsRecordedInTheHistory(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Rate', 'periodicity' => 'MONTHLY', 'metric' => 'SAVINGS_RATE', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-09', 'endPeriod' => null, 'valueType' => 'RATE', 'ratio' => '0.10']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rate', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '10.00', 'assetCode' => 'EUR'], 'version' => 1]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_goal_value_type', $this->decode()['code']);

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rate', 'ratio' => '0', 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame('0', self::arr($this->decode()['currentVersion'])['ratio']);
        self::assertSame('RATE', self::arr($this->decode()['currentVersion'])['valueType']);
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Rate', 'ratio' => '1.01', 'version' => 2]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheCurrentGoalLimitCountsOnlyGoalsWhoseEndIsNotPast(): void
    {
        for ($index = 1; $index <= 100; ++$index) {
            $this->insertGoal(sprintf('00000000-0000-7000-8000-%012d', $index), WorkspaceFixture::OWN_WORKSPACE, sprintf('00000000-0000-7000-9000-%012d', $index));
        }
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('goal_limit_reached', $this->decode()['code']);

        $this->connection->executeStatement("UPDATE budget_goals SET start_period = '2025', end_period = '2025' WHERE workspace_id = ? AND id = ?", [WorkspaceFixture::OWN_WORKSPACE, '00000000-0000-7000-8000-000000000001']);
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/api/v1/goals?pageSize=100');
        self::assertSame(100, $this->decode()['total']);
        $this->client->request('GET', '/api/v1/goals?current=false&pageSize=100');
        self::assertSame(101, $this->decode()['total']);
    }

    public function testTheDefinitionVersionLimitIsEnforced(): void
    {
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

    public function testAFutureAnnualGoalDefinitionCannotBeEditedBeforeItsFirstMonth(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Future savings', 'periodicity' => 'YEARLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2027', 'endPeriod' => '2027', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '12000.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $goal = $this->decode();
        self::assertSame('2027-01', self::arr($goal['currentVersion'])['effectiveFrom']);
        $id = $this->id($goal);

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Future savings', 'targetAmount' => ['value' => '15000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_effective_month', $this->decode()['code']);
    }

    public function testAFutureRollingGoalDefinitionCannotBeEditedBeforeItsFirstMonth(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Future ratio', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'ratio' => '0.20', 'startPeriod' => '2027-01', 'endPeriod' => null, 'valueType' => 'ROLLING_INCOME_SHARE']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Future ratio', 'ratio' => '0.25', 'targetAmount' => null, 'version' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_effective_month', $this->decode()['code']);

        // A label-only edit carries no effective month and stays allowed before the first month.
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Future ratio renamed', 'version' => 1]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/goals?period=2026');
        self::assertResponseIsSuccessful();
        self::assertCount(0, self::items($this->decode()['items']));

        $this->client->request('GET', '/api/v1/goals?period=2027');
        self::assertResponseIsSuccessful();
        $items = self::items($this->decode()['items']);
        self::assertCount(1, $items);
        // 2027 has no real month yet: the listed status names January 2027, never today's month.
        $status = self::arr(self::arr($items[0])['status']);
        self::assertSame('2027-01', $status['month']);
        self::assertSame('NOT_CALCULABLE', $status['status']);
        self::assertSame('FUTURE_MONTH', $status['reason']);
        self::assertSame('0.20', $status['ratio']);
        self::assertNull($status['target']);
        self::assertNull($status['actual']);
        self::assertNull($status['referenceIncome']);
    }

    public function testEditingTheTargetOfAnAnnualGoalWhoseYearHasElapsedIsRejected(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Past savings', 'periodicity' => 'YEARLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2025', 'endPeriod' => '2025', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '9000.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Past savings', 'targetAmount' => ['value' => '9500.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_effective_month', $this->decode()['code']);

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

    public function testEditingAnotherWorkspacesGoalAnswersNotFound(): void
    {
        $foreign = '00000000-0000-7000-8000-0000000000e9';
        $this->insertGoal($foreign, WorkspaceFixture::OTHER_WORKSPACE);

        $this->requestJson('PUT', '/api/v1/goals/'.$foreign, ['label' => 'Changed', 'targetAmount' => null, 'ratio' => null, 'version' => 1]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTargetAmountAcceptsTheTwentySixIntegerDigitBoundaryAndRejectsOneMore(): void
    {
        $atLimit = str_repeat('9', 26);
        $overLimit = str_repeat('9', 27);

        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Boundary', 'periodicity' => 'YEARLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026', 'endPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => $overLimit, 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(422);

        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Boundary', 'periodicity' => 'YEARLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026', 'endPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => $atLimit, 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($atLimit, self::arr($this->decode()['currentVersion'])['targetAmount']);
    }

    public function testRatioAcceptsOneAndTheSmallestEightDecimalStepAndRejectsOneDecimalTooMany(): void
    {
        $payload = fn (string $ratio): array => ['label' => 'Ratio boundary', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'ratio' => $ratio, 'startPeriod' => '2026-09', 'endPeriod' => null, 'valueType' => 'ROLLING_INCOME_SHARE'];

        $this->requestJson('POST', '/api/v1/goals', $payload('1'));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('1', self::arr($this->decode()['currentVersion'])['ratio']);

        $this->requestJson('POST', '/api/v1/goals', $payload('0.00000001'));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('0.00000001', self::arr($this->decode()['currentVersion'])['ratio']);

        $this->requestJson('POST', '/api/v1/goals', $payload('0.000000001'));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAToleranceIsCreatedWithTheGoalAndDefaultsToStrict(): void
    {
        $rate = ['label' => 'Rate', 'periodicity' => 'MONTHLY', 'metric' => 'SAVINGS_RATE', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-09', 'endPeriod' => null, 'valueType' => 'RATE', 'ratio' => '0.15'];

        $this->requestJson('POST', '/api/v1/goals', $rate);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('0', self::arr($this->decode()['currentVersion'])['tolerance']);

        $this->requestJson('POST', '/api/v1/goals', [...$rate, 'tolerance' => null]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('0', self::arr($this->decode()['currentVersion'])['tolerance']);

        $this->requestJson('POST', '/api/v1/goals', [...$rate, 'tolerance' => '0.01']);
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode();
        self::assertSame('0.01', self::arr($created['currentVersion'])['tolerance']);
        self::assertSame('0.010000000000000000000000', $this->scalar('SELECT tolerance::text FROM budget_goal_versions WHERE goal_id = ?', [$this->id($created)]));

        $this->requestJson('POST', '/api/v1/goals', [...$this->annualPayload(), 'tolerance' => '12000.00']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('12000.00', self::arr($this->decode()['currentVersion'])['tolerance']);
    }

    public function testAToleranceOutsideItsBoundsIsAFieldError(): void
    {
        $rate = ['label' => 'Rate', 'periodicity' => 'MONTHLY', 'metric' => 'SAVINGS_RATE', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-09', 'endPeriod' => null, 'valueType' => 'RATE', 'ratio' => '0.15'];
        foreach (['-0.01', '0.16', '0.123456789', 'abc', '1e-2'] as $tolerance) {
            $this->requestJson('POST', '/api/v1/goals', [...$rate, 'tolerance' => $tolerance]);
            self::assertResponseStatusCodeSame(422, $tolerance);
            self::assertSame('invalid_goal_tolerance', $this->decode()['code'], $tolerance);
        }
        $this->requestJson('POST', '/api/v1/goals', [...$this->annualPayload(), 'tolerance' => '12000.01']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_goal_tolerance', $this->decode()['code']);
        $this->requestJson('POST', '/api/v1/goals', [...$this->annualPayload(), 'tolerance' => '-1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_goal_tolerance', $this->decode()['code']);
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goals'));
    }

    public function testChangingOnlyTheToleranceAppendsAVersionListedInTheHistoryAndPreviewed(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Cap', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-09', 'endPeriod' => null, 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '800.00', 'assetCode' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());
        $update = ['label' => 'Cap', 'targetAmount' => ['value' => '800.00', 'assetCode' => 'EUR'], 'ratio' => null];

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/correction-preview', [...$update, 'tolerance' => '50.00', 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertNotSame([], self::items($this->decode()['months']));
        self::assertSame('1', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, [...$update, 'tolerance' => '50.00', 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, self::arr($this->decode()['currentVersion'])['versionNo']);
        self::assertSame('50.00', self::arr($this->decode()['currentVersion'])['tolerance']);

        // The same margin again, or no margin submitted, changes nothing.
        $this->requestJson('PUT', '/api/v1/goals/'.$id, [...$update, 'tolerance' => '50', 'version' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, self::arr($this->decode()['currentVersion'])['versionNo']);
        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Cap', 'version' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame('50.00', self::arr($this->decode()['currentVersion'])['tolerance']);

        // A smaller target cannot leave the margin above it.
        $this->requestJson('PUT', '/api/v1/goals/'.$id, [...$update, 'targetAmount' => ['value' => '40.00', 'assetCode' => 'EUR'], 'version' => 2]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_goal_tolerance', $this->decode()['code']);

        // Zero makes the goal strict again.
        $this->requestJson('PUT', '/api/v1/goals/'.$id, [...$update, 'tolerance' => '0', 'version' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame('0', self::arr($this->decode()['currentVersion'])['tolerance']);

        $this->client->request('GET', '/api/v1/goals/'.$id.'/versions');
        self::assertResponseIsSuccessful();
        $items = self::items($this->decode()['items']);
        self::assertCount(3, $items);
        $strict = self::arr($items[0]);
        self::assertSame(['tolerance'], array_column(self::items($strict['changes']), 'field'));
        self::assertSame(['50.00', '0'], [self::arr(self::items($strict['changes'])[0])['before'], self::arr(self::items($strict['changes'])[0])['after']]);
        self::assertSame('0', $strict['tolerance']);
        self::assertSame('50.00', self::arr($items[1])['tolerance']);
        self::assertSame(['tolerance'], array_column(self::items(self::arr($items[1])['changes']), 'field'));
        self::assertSame(['valueType', 'targetAmount'], array_column(self::items(self::arr($items[2])['changes']), 'field'));
    }

    public function testAToleranceOnlyAnnualRevisionPreservesTheCalendarAndRejectsAStaleVersion(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode();
        $id = $this->id($created);
        $calendar = self::arr($created['currentVersion'])['schedule'];

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => $created['label'], 'tolerance' => '500.00', 'version' => 1]);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertSame('500.00', self::arr($updated['currentVersion'])['tolerance']);
        self::assertSame($calendar, self::arr($updated['currentVersion'])['schedule']);
        self::assertSame(2, self::arr($updated['currentVersion'])['versionNo']);

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => $created['label'], 'tolerance' => '400.00', 'version' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));
    }

    public function testAnEditedToleranceReachesTheEvaluationsAndForeignWorkspacesStayIsolated(): void
    {
        $this->requestJson('POST', '/api/v1/goals', ['label' => 'Rate', 'periodicity' => 'MONTHLY', 'metric' => 'SAVINGS_RATE', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026-09', 'endPeriod' => null, 'valueType' => 'RATE', 'ratio' => '0.15', 'tolerance' => '0.01']);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/v1/goals/evaluations?from=2026-09&to=2026-09&granularity=MONTH');
        self::assertResponseIsSuccessful();
        $item = self::arr(self::items(self::arr(self::items($this->decode()['months'])[0])['items'])[0]);
        self::assertSame(['ratio' => '0.01', 'percentDisplay' => '1.00'], $item['tolerance']);
        self::assertSame('0.01', self::arr(self::arr($item['formula'])['operands'])['tolerance'] ?? null);
        self::assertArrayHasKey('withinTolerance', $item);

        $foreign = '00000000-0000-7000-8000-0000000000e9';
        $this->insertGoal($foreign, WorkspaceFixture::OTHER_WORKSPACE);
        $this->requestJson('PUT', '/api/v1/goals/'.$foreign, ['label' => 'Changed', 'tolerance' => '1.00', 'version' => 1]);
        self::assertResponseStatusCodeSame(404);
        $this->requestJson('POST', '/api/v1/goals/'.$foreign.'/correction-preview', ['label' => 'Changed', 'tolerance' => '1.00', 'version' => 1]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnExistingDefinitionRowIsStrictByDefaultAfterTheMigration(): void
    {
        $this->insertGoal('00000000-0000-7000-8000-0000000000e8', WorkspaceFixture::OTHER_WORKSPACE);

        self::assertSame('0', $this->scalar('SELECT tolerance::int FROM budget_goal_versions WHERE goal_id = ?', ['00000000-0000-7000-8000-0000000000e8']));
        self::assertSame('0', $this->scalar('SELECT tolerance_scale FROM budget_goal_versions WHERE goal_id = ?', ['00000000-0000-7000-8000-0000000000e8']));
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

    public function testAFutureAnnualGoalCanBeRenamedWithoutSubmittingAFutureDefinitionMonth(): void
    {
        $this->requestJson('POST', '/api/v1/goals', [...$this->annualPayload(), 'startPeriod' => '2027', 'endPeriod' => '2027', 'valueType' => 'AMOUNT']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());

        $this->requestJson('PUT', '/api/v1/goals/'.$id, ['label' => 'Future project', 'version' => 1]);

        self::assertResponseIsSuccessful();
        $renamed = $this->decode();
        self::assertSame('Future project', $renamed['label']);
        self::assertSame(2, $renamed['version']);
        self::assertCount(1, self::items($renamed['versions']));
    }

    public function testItPreviewsAndSavesARetroactiveCorrectionWithAppendOnlyHistory(): void
    {
        $this->clock->modify('2026-07-05T00:00:00+00:00');
        $this->refreshCsrf();
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());
        $this->requestJson('PUT', '/api/v1/goals/'.$id, [
            'label' => 'Annual savings',
            'targetAmount' => ['value' => '24000.00', 'assetCode' => 'EUR'],
            'ratio' => null,
            'version' => 1,
        ]);
        self::assertResponseIsSuccessful();

        $this->clock->modify(self::TODAY);
        $this->refreshCsrf();
        $payload = [
            'label' => 'Annual savings',
            'targetAmount' => ['value' => '18000.00', 'assetCode' => 'EUR'],
            'ratio' => null,
            'version' => 2,
            'effectiveFrom' => '2026-01',
            'reason' => 'Correct the target recorded at creation.',
        ];
        $beforeRows = $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]);
        $beforeAudits = $this->scalar('SELECT count(*) FROM audit_events WHERE entity_id = ?', [$id]);

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/correction-preview', $payload);
        self::assertResponseIsSuccessful();
        $months = self::items($this->decode()['months']);
        self::assertCount(9, $months);
        self::assertSame('2026-01', self::arr($months[0])['month']);
        self::assertSame(1, self::arr(self::arr($months[0])['before'])['goalVersion']);
        self::assertSame('1000.00', self::arr(self::arr($months[0])['before'])['expected']);
        self::assertSame(3, self::arr(self::arr($months[0])['after'])['goalVersion']);
        self::assertSame('1500.00', self::arr(self::arr($months[0])['after'])['expected']);
        self::assertSame('2026-06', self::arr($months[5])['month']);
        self::assertSame(3, self::arr(self::arr($months[5])['after'])['goalVersion']);
        self::assertSame('9000.00', self::arr(self::arr($months[5])['after'])['expected']);
        self::assertSame('2026-07', self::arr($months[6])['month']);
        self::assertSame(2, self::arr(self::arr($months[6])['after'])['goalVersion']);
        self::assertSame('11000.00', self::arr(self::arr($months[6])['after'])['expected']);
        self::assertSame('2026-09', self::arr($months[8])['month']);
        self::assertSame('12000.00', self::arr(self::arr($months[8])['before'])['expected']);
        self::assertSame(2, self::arr(self::arr($months[8])['after'])['goalVersion']);
        self::assertSame('15000.00', self::arr(self::arr($months[8])['after'])['expected']);
        self::assertSame($beforeRows, $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));
        self::assertSame($beforeAudits, $this->scalar('SELECT count(*) FROM audit_events WHERE entity_id = ?', [$id]));

        $this->requestJson('PUT', '/api/v1/goals/'.$id, $payload);
        self::assertResponseIsSuccessful();
        self::assertSame(2, self::arr($this->decode()['currentVersion'])['versionNo']);

        $this->client->request('GET', '/api/v1/goals/'.$id.'/versions');
        self::assertResponseIsSuccessful();
        $items = self::items($this->decode()['items']);
        self::assertCount(3, $items);
        $correction = self::arr($items[0]);
        self::assertSame(3, $correction['versionNo']);
        self::assertTrue($correction['retroactive']);
        self::assertSame($payload['reason'], $correction['reason']);
        self::assertSame(WorkspaceFixture::OWNER_ID, self::arr($correction['createdBy'])['id']);
        self::assertSame('Owner', self::arr($correction['createdBy'])['displayName']);
        self::assertSame(['targetAmount', 'schedule'], array_column(self::items($correction['changes']), 'field'));
        $targetChange = self::arr(self::items($correction['changes'])[0]);
        self::assertSame('12000.00', $targetChange['before']);
        self::assertSame('18000.00', $targetChange['after']);

        $event = $this->connection->fetchAssociative('SELECT event_type, after_json::text AS payload FROM audit_events WHERE entity_id = ? ORDER BY occurred_at DESC LIMIT 1', [$id]);
        self::assertIsArray($event);
        self::assertSame('goal.corrected', $event['event_type']);
        $eventPayload = $event['payload'] ?? null;
        self::assertIsString($eventPayload);
        self::assertStringContainsString('targetAmount,schedule', $eventPayload);
        self::assertStringNotContainsString('18000', $eventPayload);

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?month=2026-06');
        self::assertResponseIsSuccessful();
        $june = $this->decode();
        self::assertSame(3, $june['goalVersion']);
        self::assertSame('9000.00', $june['expected']);

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?month=2026-07');
        self::assertResponseIsSuccessful();
        $july = $this->decode();
        self::assertSame(2, $july['goalVersion']);
        self::assertSame('11000.00', $july['expected']);

        $this->client->request('GET', '/api/v1/goals/'.$id.'/status?month=2026-09');
        self::assertResponseIsSuccessful();
        $september = $this->decode();
        self::assertSame(2, $september['goalVersion']);
        self::assertSame('15000.00', $september['expected']);
    }

    public function testRetroactiveCorrectionValidationAndWorkspaceIsolation(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        $id = $this->id($this->decode());
        $base = ['label' => 'Annual savings', 'targetAmount' => ['value' => '13000.00', 'assetCode' => 'EUR'], 'ratio' => null, 'version' => 1];

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/correction-preview', [...$base, 'effectiveFrom' => '2026-01']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('correction_reason_required', $this->decode()['code']);

        foreach (['2025-12', '2027-01', 'invalid'] as $month) {
            $this->requestJson('PUT', '/api/v1/goals/'.$id, [...$base, 'effectiveFrom' => $month, 'reason' => 'A valid correction reason.']);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('invalid_effective_month', $this->decode()['code']);
        }

        $foreign = '00000000-0000-7000-8000-0000000000e9';
        $this->insertGoal($foreign, WorkspaceFixture::OTHER_WORKSPACE);
        $this->client->request('GET', '/api/v1/goals/'.$foreign.'/versions');
        self::assertResponseStatusCodeSame(404);
        $this->requestJson('POST', '/api/v1/goals/'.$foreign.'/correction-preview', [...$base, 'effectiveFrom' => '2026-01', 'reason' => 'A valid correction reason.']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnnualCorrectionPreviewStopsAtTheGoalYearEnd(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());
        $this->clock->modify('2027-03-14T09:12:04+00:00');
        $this->refreshCsrf();

        $this->requestJson('POST', '/api/v1/goals/'.$id.'/correction-preview', [
            'label' => 'Annual savings',
            'targetAmount' => ['value' => '13000.00', 'assetCode' => 'EUR'],
            'version' => 1,
            'effectiveFrom' => '2026-01',
            'reason' => 'Correct the original annual target.',
        ]);

        self::assertResponseIsSuccessful();
        $months = self::items($this->decode()['months']);
        self::assertCount(12, $months);
        self::assertSame('2026-01', self::arr($months[0])['month']);
        self::assertSame('2026-12', self::arr($months[11])['month']);
    }

    public function testRollingCorrectionSpanIsBoundedAtOneHundredTwentyMonthsForPreviewAndSave(): void
    {
        $rolling = static fn (string $start): array => ['label' => 'Old ratio', 'periodicity' => 'MONTHLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'ratio' => '0.30', 'startPeriod' => $start, 'endPeriod' => null, 'valueType' => 'ROLLING_INCOME_SHARE'];
        $correction = static fn (string $from): array => ['label' => 'Old ratio', 'ratio' => '0.35', 'targetAmount' => null, 'version' => 1, 'effectiveFrom' => $from, 'reason' => 'Correct the original ratio.'];

        // At 2026-09, 2016-09 is the 121st month and 2016-10 the 120th.
        $this->requestJson('POST', '/api/v1/goals', $rolling('1900-01'));
        self::assertResponseStatusCodeSame(201);
        $tooOld = $this->id($this->decode());
        $this->refreshCsrf();
        foreach (['POST' => '/correction-preview', 'PUT' => ''] as $method => $suffix) {
            $this->requestJson($method, '/api/v1/goals/'.$tooOld.$suffix, $correction('2016-09'));
            self::assertResponseStatusCodeSame(422);
            self::assertSame('correction_span_exceeded', $this->decode()['code']);
        }

        $this->requestJson('POST', '/api/v1/goals/'.$tooOld.'/correction-preview', $correction('2016-10'));
        self::assertResponseIsSuccessful();
        self::assertCount(120, self::items($this->decode()['months']));
    }

    public function testConcurrentDefinitionUpdatesSerializeAndOnlyOneVersionIsAppended(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $id = $this->id($this->decode());
        $barrier = sys_get_temp_dir().'/cadran-goal-update-'.bin2hex(random_bytes(8));
        $processes = [];
        $pipes = [];

        try {
            $this->connection->beginTransaction();
            $this->connection->fetchOne(
                'SELECT id FROM budget_goals WHERE workspace_id = ? AND id = ? FOR UPDATE',
                [WorkspaceFixture::OWN_WORKSPACE, $id],
            );
            foreach (['13000.00', '14000.00'] as $worker => $amount) {
                $workerPipes = [];
                $process = proc_open(
                    [PHP_BINARY, 'tests/Support/ConcurrentGoalUpdateWorker.php', $id, $amount, $barrier, (string) $worker],
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
            self::assertSame(['ok', 'stale_goal_version'], $outcomes);
            self::assertSame('2', $this->scalar('SELECT count(*) FROM budget_goal_versions WHERE goal_id = ?', [$id]));
            self::assertSame('2', $this->scalar('SELECT version FROM budget_goals WHERE id = ?', [$id]));
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

    /** @return array<string, mixed> */
    private function annualPayload(): array
    {
        return ['label' => 'Annual savings', 'periodicity' => 'YEARLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null], 'startPeriod' => '2026', 'endPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '12000.00', 'assetCode' => 'EUR']];
    }

    /** @return array<string, mixed> */
    private function categoryPayload(string $id): array
    {
        return ['label' => 'Leisure cap', 'periodicity' => 'YEARLY', 'metric' => 'CATEGORY_SPENDING', 'scope' => ['type' => 'CATEGORY', 'ref' => $id], 'startPeriod' => '2026', 'endPeriod' => '2026', 'valueType' => 'AMOUNT', 'targetAmount' => ['value' => '1200.00', 'assetCode' => 'EUR']];
    }

    private function insertCategory(string $id, string $workspaceId): void
    {
        $this->connection->insert('category_categories', ['id' => $id, 'workspace_id' => $workspaceId, 'type' => 'EXPENSE', 'label' => 'Leisure '.substr($id, -2), 'parent_id' => null, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1, 'version' => 1, 'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00', 'archived_at' => null]);
    }

    private function closeMonth(int $year, int $month, string $id): void
    {
        $this->connection->insert('account_period_closures', ['id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'year' => $year, 'month' => $month, 'closed_at' => '2026-09-01 10:00:00+00', 'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1]);
    }

    private function insertGoal(string $id, string $workspace, string $versionId = '00000000-0000-7000-8000-0000000000f9'): void
    {
        $this->connection->insert('budget_goals', ['id' => $id, 'workspace_id' => $workspace, 'label' => 'Foreign', 'periodicity' => 'YEARLY', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'start_period' => '2026', 'end_period' => '2026', 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID, 'version' => 1]);
        $this->insertVersion($versionId, $workspace, $id, 1);
    }

    private function insertAccount(string $id, ?string $archivedAt): void
    {
        $this->connection->insert('account_financial_accounts', ['id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Account '.substr($id, -2), 'asset_code' => 'EUR', 'kind' => 'SAVINGS', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true, 'include_in_emergency_fund' => true, 'opened_on' => '2026-01-01', 'closed_on' => null, 'version' => 1, 'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00', 'used_at' => null, 'archived_at' => $archivedAt]);
    }

    private function insertVersion(string $id, string $workspace, string $goalId, int $number): void
    {
        $this->connection->insert('budget_goal_versions', ['id' => $id, 'workspace_id' => $workspace, 'goal_id' => $goalId, 'version_no' => $number, 'effective_from' => '2026-09', 'value_type' => 'AMOUNT', 'target_amount' => '100.00', 'target_scale' => 2, 'ratio' => null, 'ratio_scale' => null, 'schedule_mode' => null, 'reason' => null, 'created_at' => '2026-09-01 12:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID]);
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

    public function testCreationReplaysTheFirstResponseForTheSameKeyAndBodyAndRefusesAChangedBody(): void
    {
        $key = 'goal-create-key-00000001';
        $send = function (array $body) use ($key): void {
            $this->client->request('POST', '/api/v1/goals', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key], content: json_encode($body, JSON_THROW_ON_ERROR));
        };

        $send($this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->client->getResponse()->headers->has('Idempotency-Replayed'));
        $first = $this->id($this->decode());

        $send($this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->id($this->decode()));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM budget_goals WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]));

        $send([...$this->annualPayload(), 'label' => 'Another label']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM budget_goals WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]));

        $this->requestJson('POST', '/api/v1/session', ['email' => WorkspaceFixture::OTHER_OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $send($this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->client->getResponse()->headers->has('Idempotency-Replayed'));
        self::assertNotSame($first, $this->id($this->decode()));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM budget_goals WHERE workspace_id = ?', [WorkspaceFixture::OWN_WORKSPACE]));
    }

    public function testTheListIsPagedAndRejectsOutOfRangePagination(): void
    {
        $this->requestJson('POST', '/api/v1/goals', $this->annualPayload());
        self::assertResponseStatusCodeSame(201);
        $this->requestJson('POST', '/api/v1/goals', [...$this->annualPayload(), 'label' => 'Second']);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/v1/goals?pageSize=1&page=2');
        self::assertResponseIsSuccessful();
        $page = $this->decode();
        self::assertCount(1, self::items($page['items']));
        self::assertSame(2, $page['page']);
        self::assertSame(1, $page['pageSize']);
        self::assertSame(2, $page['total']);

        foreach (['pageSize=0', 'pageSize=101', 'page=0', 'page=1001', 'pageSize=abc'] as $query) {
            $this->client->request('GET', '/api/v1/goals?'.$query);
            self::assertResponseStatusCodeSame(400, $query);
        }
    }
}
