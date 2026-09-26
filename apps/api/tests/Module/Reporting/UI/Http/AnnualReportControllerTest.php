<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\UI\Http;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AnnualReportControllerTest extends WebTestCase
{
    use ClosesPeriods;

    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000a1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000a9';
    private const string CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string SECOND_CATEGORY = '00000000-0000-7000-8000-0000000000c2';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    /** A fully elapsed year, so the report never depends on the day the suite runs. */
    private int $year;

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
        $this->year = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y') - 1;
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testEveryMonthlyCellEqualsTheMonthlyReportAndTheTotalIsTheSumOfTheMonths(): void
    {
        $this->signIn();
        $this->seedYear();

        $report = $this->annual($this->year);

        self::assertSame($this->year, $report['year']);
        self::assertSame('exclude', $report['incompleteMonths']);
        self::assertSame(['state' => 'SINGLE', 'version' => 1, 'label' => 'Définition de trésorerie', 'versions' => [1]], $report['metricPolicy']);
        $rows = self::rows($report);
        self::assertCount(12, $rows);
        $monthlySum = [];
        foreach ($rows as $index => $row) {
            $month = sprintf('%d-%02d', $this->year, $index + 1);
            self::assertSame($month, $row['month']);
            self::assertSame('COMPLETE', $row['state']);
            $monthly = $this->get('/api/v1/reports/monthly?month='.$month);
            foreach (['cashIncome', 'budgetExpenses', 'budgetSurplus', 'cashSavingsRate', 'netSavingsTransfers', 'netSavingsRate'] as $column) {
                self::assertSame(self::metricOf($monthly, $column), self::cell($row, $column), $column.' '.$month);
            }
            $monthlySum[] = DecimalValue::fromString(self::text(self::cell($row, 'cashIncome')));
        }
        $aggregate = self::aggregate($report, 'cashIncome');
        self::assertDecimal(ExactDecimal::sum(...$monthlySum)->toString(), $aggregate['total']);
        self::assertDecimal('78000', $aggregate['total']);
        self::assertDecimal('6500', $aggregate['average']);
        self::assertNull($aggregate['totalReason']);
        // A rate is the sum of surpluses over the sum of incomes: (78000 - 7800) / 78000 = 0.9.
        $rate = self::aggregate($report, 'cashSavingsRate');
        self::assertDecimal('0.9', $rate['total']);
        self::assertNull(self::aggregate($report, 'endNetWorth')['total']);
        self::assertSame('NOT_ADDITIVE', self::aggregate($report, 'endNetWorth')['totalReason']);
        self::assertStringNotContainsString('999999', (string) $this->client->getResponse()->getContent());
        $charts = self::fields($report['charts']);
        $top = self::fields($charts['topExpenseCategories']);
        self::assertNull($top['reason']);
        $items = self::rowsOf($top['items']);
        self::assertSame(self::CATEGORY, $items[0]['categoryId']);
        self::assertDecimal('7800', $items[0]['total']);
    }

    public function testAnArchivedBudgetCategoryWithoutMovementDoesNotBreakTheChartAndFlowsIgnoreTheSelection(): void
    {
        $this->signIn();
        $this->seedYear();
        $this->connection->executeStatement("UPDATE category_categories SET archived_at = '2000-06-01 00:00:00+00' WHERE id = ?", [self::SECOND_CATEGORY]);
        $this->put(['budgetSurplus'], 'exclude', 0);

        $report = $this->annual($this->year);

        $charts = self::fields($report['charts']);
        self::assertNull(self::fields($charts['topExpenseCategories'])['reason']);
        self::assertSame('EUR', self::fields($charts['topExpenseCategories'])['assetCode']);
        $flows = self::fields($charts['flows']);
        self::assertSame('EUR', $flows['assetCode']);
        $months = self::rowsOf($flows['months']);
        self::assertCount(12, $months);
        self::assertDecimal('1000', self::fields($months[0]['cashIncome'])['value']);
        self::assertDecimal('100', self::fields($months[0]['budgetExpenses'])['value']);
        $netWorth = self::fields($charts['netWorth']);
        self::assertCount(12, self::rowsOf($netWorth['months']));
        self::assertArrayHasKey('assetCode', $netWorth);
        self::assertArrayHasKey('assetCode', self::fields($charts['allocation']));
    }

    public function testAClosedMonthIsFrozenInASnapshotAndReopeningComputesItLiveAgain(): void
    {
        $this->signIn();
        $this->seedYear();
        $this->closeThroughTheApi(3);

        self::assertSame(1, $this->snapshotCount());
        $before = self::rows($this->annual($this->year))[2];
        self::assertTrue($before['closed']);
        self::assertTrue($before['snapshot']);
        self::assertDecimal('300', self::cell($before, 'budgetExpenses'));

        $this->connection->executeStatement('UPDATE category_categories SET budget_included = false WHERE id = ?', [self::CATEGORY], [ParameterType::STRING]);
        $frozen = self::rows($this->annual($this->year));
        self::assertSame(self::cell($before, 'budgetExpenses'), self::cell($frozen[2], 'budgetExpenses'));
        self::assertSame('0', self::cell($frozen[3], 'budgetExpenses'));

        $this->call('POST', '/api/v1/periods/'.$this->year.'-03/reopening', ['version' => 1, 'reason' => 'Late invoice']);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->snapshotCount());
        $live = self::rows($this->annual($this->year))[2];
        self::assertFalse($live['closed']);
        self::assertFalse($live['snapshot']);
        self::assertSame('0', self::cell($live, 'budgetExpenses'));
    }

    public function testAClosedMonthWithoutSnapshotIsCapturedByTheFirstRead(): void
    {
        $this->signIn();
        $this->seedYear();
        $this->closeMonthInDatabase($this->connection, $this->year, 5);

        $row = self::rows($this->annual($this->year))[4];

        self::assertTrue($row['snapshot']);
        self::assertSame(1, $this->snapshotCount());
        self::assertSame(1, $this->connection->fetchOne('SELECT policy_version FROM reporting_month_snapshots'));
    }

    public function testASnapshotOfAnEarlierClosureIsNeverServed(): void
    {
        $this->signIn();
        $this->seedYear();
        $this->closeMonthInDatabase($this->connection, $this->year, 5);
        $this->annual($this->year);
        $this->connection->executeStatement("UPDATE reporting_month_snapshots SET closure_id = '00000000-0000-7000-8000-00000000dead', payload = jsonb_set(payload, '{cells,cashIncome,value}', '\"999999\"')");

        $row = self::rows($this->annual($this->year))[4];

        self::assertDecimal('5000', self::cell($row, 'cashIncome'));
        self::assertSame(2, $this->snapshotCount());
    }

    public function testASnapshotWithAnotherSchemaVersionIsRecaptured(): void
    {
        $this->signIn();
        $this->seedYear();
        $this->closeMonthInDatabase($this->connection, $this->year, 5);
        $this->annual($this->year);
        $this->connection->executeStatement("UPDATE reporting_month_snapshots SET schema_version = 99, payload = jsonb_set(payload, '{cells,cashIncome,value}', '\"999999\"')");

        $row = self::rows($this->annual($this->year))[4];

        self::assertDecimal('5000', self::cell($row, 'cashIncome'));
        self::assertSame(1, $this->connection->fetchOne('SELECT schema_version FROM reporting_month_snapshots'));
    }

    public function testAPreviousYearUnderAnotherPolicyGivesAPolicyMismatchAverage(): void
    {
        $this->signIn();
        $this->seedYear();
        $previous = $this->year - 1;
        for ($month = 1; $month <= 12; ++$month) {
            $this->connection->insert('account_period_closures', [
                'id' => sprintf('00000000-0000-7000-8000-%012d', $previous * 100 + $month), 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'year' => $previous, 'month' => $month, 'closed_at' => $previous.'-12-01 10:00:00+00',
                'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
            ]);
        }
        $this->transaction('41', self::ACCOUNT, '500.00', 'INCOME', $previous.'-01-10');
        $this->policyVersionTwoFrom(($this->year - 1).'-12-15 00:00:00+00');
        $this->put(['cashIncome'], 'exclude', 0);

        $report = $this->annual($this->year);

        self::assertSame(2, self::fields($report['metricPolicy'])['version']);
        $aggregate = self::aggregate($report, 'cashIncome');
        self::assertNull($aggregate['previousYearAverage']);
        self::assertSame('POLICY_MISMATCH', $aggregate['previousYearAverageReason']);
        self::assertDecimal('6500', $aggregate['average']);
    }

    public function testAYearAcrossTwoPolicyVersionsIsMixedAndItsAggregatesAreNull(): void
    {
        $this->signIn();
        $this->seedYear();
        $this->connection->insert('account_period_closures', [
            'id' => sprintf('00000000-0000-7000-8000-%012d', $this->year * 100 + 1), 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'year' => $this->year, 'month' => 1, 'closed_at' => $this->year.'-02-10 10:00:00+00',
            'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);
        $this->policyVersionTwoFrom($this->year.'-06-01 00:00:00+00');
        $this->put(['cashIncome'], 'exclude', 0);

        $report = $this->annual($this->year);

        $policy = self::fields($report['metricPolicy']);
        self::assertSame('MIXED', $policy['state']);
        self::assertNull($policy['version']);
        self::assertSame([1, 2], $policy['versions']);
        $aggregate = self::aggregate($report, 'cashIncome');
        self::assertNull($aggregate['total']);
        self::assertSame('MIXED_METRIC_POLICIES', $aggregate['totalReason']);
        self::assertSame('MIXED_METRIC_POLICIES', $aggregate['previousYearAverageReason']);
        self::assertDecimal('1000', self::cell(self::rows($report)[0], 'cashIncome'));
    }

    public function testAYearBeforeTheFirstDataMonthIsEntirelyNoData(): void
    {
        $this->signIn();
        $this->seedYear();

        $report = $this->annual(2001);

        self::assertSame('EMPTY', $report['quality']);
        foreach (self::rows($report) as $row) {
            self::assertSame('NO_DATA', $row['state']);
            self::assertSame(['value' => null, 'reason' => 'NO_DATA'], self::fields(self::fields($row['cells'])['cashIncome']));
        }
        self::assertSame('EMPTY_POPULATION', self::aggregate($report, 'cashIncome')['totalReason']);
        self::assertSame('EMPTY_POPULATION', self::fields(self::fields($report['charts'])['allocation'])['reason']);
    }

    public function testTheRunningYearShowsFutureMonthsAndAnIncompleteSettingOverride(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $this->transaction('01', self::ACCOUNT, '1000.00', 'INCOME', $today->format('Y-m-01'));

        $excluded = $this->annual((int) $today->format('Y'));
        $included = $this->annual((int) $today->format('Y'), '?incompleteMonths=include');

        $currentIndex = (int) $today->format('n') - 1;
        self::assertSame('PROVISIONAL', self::rows($excluded)[$currentIndex]['state']);
        if ($currentIndex < 11) {
            self::assertSame('FUTURE', self::rows($excluded)[11]['state']);
            self::assertSame(['value' => null, 'reason' => 'FUTURE'], self::fields(self::fields(self::rows($excluded)[11]['cells'])['cashIncome']));
        }
        self::assertSame('EMPTY_POPULATION', self::aggregate($excluded, 'cashIncome')['averageReason']);
        self::assertDecimal('1000', self::aggregate($included, 'cashIncome')['average']);
        self::assertDecimal('1000', self::aggregate($excluded, 'cashIncome')['total']);
        self::assertSame($currentIndex > 0 ? 'PARTIAL' : 'PROVISIONAL', $excluded['quality']);
    }

    public function testYearBoundsAndAnonymousAccessAreRefused(): void
    {
        $this->client->request('GET', '/api/v1/reports/annual/'.$this->year);
        self::assertResponseStatusCodeSame(401);
        $this->signIn();

        foreach ([1899, (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y') + 1] as $year) {
            $this->client->request('GET', '/api/v1/reports/annual/'.$year);
            self::assertResponseStatusCodeSame(422);
            self::assertResponseHeaderSame('content-type', 'application/problem+json');
            self::assertSame('/problems/invalid-report-year', self::fields(json_decode((string) $this->client->getResponse()->getContent(), true))['type']);
        }
        $this->client->request('GET', '/api/v1/reports/annual/1900');
        self::assertResponseStatusCodeSame(200);
        $this->client->request('GET', '/api/v1/reports/annual/'.$this->year.'?incompleteMonths=maybe');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/v1/reports/annual/abc');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherWorkspaceNeverSeesRowsColumnsOrSnapshots(): void
    {
        $this->signIn();
        $this->seedYear();
        $this->closeMonthInDatabase($this->connection, $this->year, 5);
        $this->annual($this->year);
        $this->put(['cashIncome', 'category:'.self::CATEGORY], 'exclude', 0);
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Secret', 'CURRENT');
        $this->transaction('91', self::OTHER_ACCOUNT, '999999.00', 'INCOME', $this->year.'-02-10', WorkspaceFixture::OTHER_WORKSPACE);

        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $report = $this->annual($this->year);

        $ids = array_map(static fn (array $column): mixed => $column['id'], self::rowsOf($report['columns']));
        self::assertNotContains('category:'.self::CATEGORY, $ids);
        self::assertDecimal('999999', self::cell(self::rows($report)[1], 'cashIncome'));
        self::assertFalse(self::rows($report)[4]['closed']);
        self::assertStringNotContainsString('5000', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/v1/reports/annual/'.$this->year.'/columns/category:'.self::CATEGORY.'/explain');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/reports/annual/'.$this->year.'/columns/account:'.self::ACCOUNT.'/explain');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAStoredColumnWhoseReferenceNoLongerResolvesStaysWithNullCells(): void
    {
        $this->signIn();
        $this->seedYear();
        $this->put(['cashIncome'], 'exclude', 0);
        $this->connection->executeStatement(
            'UPDATE reporting_annual_report_preferences SET columns = ?::jsonb',
            [json_encode(['cashIncome', 'category:00000000-0000-7000-8000-0000000000c9', 'account:'.self::OTHER_ACCOUNT])],
        );

        $report = $this->annual($this->year);

        $row = self::rows($report)[0];
        $foreign = self::fields(self::fields($row['cells'])['category:00000000-0000-7000-8000-0000000000c9']);
        self::assertSame(['value' => null, 'reason' => 'UNKNOWN_REFERENCE'], $foreign);
        self::assertSame('Référence inconnue', self::rowsOf($report['columns'])[1]['label']);
        self::assertNull(self::aggregate($report, 'category:00000000-0000-7000-8000-0000000000c9')['total']);
    }

    public function testAColumnExplanationListsTheCountedMonthsAndTheFormula(): void
    {
        $this->signIn();
        $this->seedYear();

        $explanation = $this->get('/api/v1/reports/annual/'.$this->year.'/columns/cashIncome/explain');

        self::assertSame('cashIncome', $explanation['column']);
        self::assertSame('FLOW', $explanation['kind']);
        self::assertIsString($explanation['formula']);
        $months = self::rowsOf($explanation['months']);
        self::assertCount(12, $months);
        self::assertTrue($months[0]['counted']);
        self::assertNull($months[0]['exclusionReason']);
        self::assertDecimal('78000', self::fields($explanation['aggregate'])['total']);
        self::assertSame(self::fields($explanation['policy'])['version'], 1);

        $this->client->request('GET', '/api/v1/reports/annual/'.$this->year.'/columns/nope/explain');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/reports/annual/'.$this->year.'/columns/axis:FIXED/explain');
        self::assertResponseStatusCodeSame(200);
    }

    private function seedYear(): void
    {
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::CATEGORY, 'Loyer');
        $this->category(self::SECOND_CATEGORY, 'Loisirs');
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $this->year, $month);
            $this->transaction(sprintf('%02d', $month), self::ACCOUNT, sprintf('%d.00', $month * 1000), 'INCOME', $day);
            $this->transaction(sprintf('%02d', 20 + $month), self::ACCOUNT, sprintf('-%d.00', $month * 100), 'EXPENSE', $day, split: self::CATEGORY);
        }
    }

    /** @return array<string, mixed> */
    private function annual(int $year, string $query = ''): array
    {
        return $this->get('/api/v1/reports/annual/'.$year.$query);
    }

    private function closeThroughTheApi(int $month): void
    {
        $uri = sprintf('/api/v1/periods/%d-%02d/closure', $this->year, $month);
        $body = $this->call('PUT', $uri, []);
        if (409 === $this->client->getResponse()->getStatusCode()) {
            $overrides = [];
            foreach (self::rowsOf($body['blockers']) as $blocker) {
                self::assertIsString($blocker['condition']);
                $overrides[$blocker['condition']] = 'Confirmed for the test';
            }
            $this->call('PUT', $uri, ['overrides' => $overrides]);
        }
        self::assertResponseStatusCodeSame(201);
    }

    private function snapshotCount(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM reporting_month_snapshots');
        self::assertIsInt($count);

        return $count;
    }

    private function policyVersionTwoFrom(string $activeFrom): void
    {
        $this->connection->insert('reporting_metric_policies', [
            'id' => '00000000-0000-7000-8000-000000000002', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'version' => 2,
            'label' => 'Politique deux', 'cash_excluded_account_kinds' => '[]',
            'savings_rate_formula' => 'BUDGET_SURPLUS_OVER_CASH_INCOME',
            'net_savings_rate_formula' => 'NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME',
            'created_at' => '2020-01-01 10:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID,
        ]);
        $this->connection->insert('reporting_metric_policy_activations', [
            'id' => '00000000-0000-7000-8000-000000000012', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'policy_version' => 2,
            'active_from' => $activeFrom, 'created_by' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    private function signIn(string $email = WorkspaceFixture::OWNER_EMAIL): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @param list<string> $columns */
    private function put(array $columns, string $incomplete, int $version): void
    {
        $this->call('PUT', '/api/v1/reports/annual/preferences', ['columns' => $columns, 'incompleteMonths' => $incomplete, 'version' => $version]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return self::fields(json_decode((string) $this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return self::fields(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    private function account(string $id, string $workspace, string $label, string $kind): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'asset_code' => 'EUR', 'kind' => $kind,
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => '2000-01-01', 'version' => 1,
            'created_at' => '2000-01-01 12:00:00+00', 'updated_at' => '2000-01-01 12:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function category(string $id, string $label): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'type' => 'EXPENSE', 'label' => $label,
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2000-01-01 12:00:00+00', 'updated_at' => '2000-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
    }

    private function transaction(
        string $suffix,
        string $account,
        string $amount,
        string $nature,
        string $bookedOn,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        ?string $split = null,
    ): void {
        $id = '00000000-0000-7000-8000-0000000001'.$suffix;
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => $workspace, 'account_id' => $account, 'asset_code' => 'EUR',
            'amount_value' => $amount, 'amount_scale' => 2, 'state' => 'BOOKED', 'nature' => $nature,
            'source' => 'MANUAL', 'booked_on' => $bookedOn, 'raw_label' => 'Annual '.$suffix,
            'version' => 1, 'created_at' => $bookedOn.' 12:00:00+00', 'updated_at' => $bookedOn.' 12:00:00+00',
        ]);
        if (null !== $split) {
            $this->connection->insert('transaction_splits', [
                'id' => '00000000-0000-7000-8000-0000000002'.$suffix, 'workspace_id' => $workspace,
                'transaction_id' => $id, 'category_id' => $split, 'amount_value' => $amount,
                'amount_scale' => 2, 'asset_code' => 'EUR', 'created_at' => $bookedOn.' 12:00:00+00',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $report): array
    {
        return self::rowsOf($report['rows']);
    }

    /** @return list<array<string, mixed>> */
    private static function rowsOf(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_map(static fn (mixed $row): array => self::fields($row), $value));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function cell(array $row, string $column): mixed
    {
        return self::fields(self::fields($row['cells'])[$column])['value'];
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private static function aggregate(array $report, string $column): array
    {
        return self::fields(self::fields($report['aggregates'])[$column]);
    }

    /**
     * @param array<string, mixed> $monthly
     */
    private static function metricOf(array $monthly, string $key): mixed
    {
        return self::fields($monthly[$key])['value'];
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function assertDecimal(string $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertSame(0, DecimalValue::fromString($expected)->compareTo(DecimalValue::fromString($actual)), $expected.' vs '.$actual);
    }

    /** @return array<string, mixed> */
    private static function fields(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
