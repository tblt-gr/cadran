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

final class MetricPolicyControllerTest extends WebTestCase
{
    private const string CURRENT = '00000000-0000-7000-8000-0000000000a1';
    private const string VOUCHER = '00000000-0000-7000-8000-0000000000a2';
    private const string CLOSURE = '00000000-0000-7000-8000-0000000000d1';
    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private string $month;
    private \DateTimeImmutable $periodStart;

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

        $paris = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $today = new \DateTimeImmutable($paris->format('Y-m-d'), new \DateTimeZone('UTC'));
        $this->periodStart = $today->modify('first day of last month');
        $this->month = $this->periodStart->format('Y-m');
        $this->signIn();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testWithoutAnyActivationTheBuiltInVersionOneIsActive(): void
    {
        $catalog = $this->call('GET', '/api/v1/reports/metric-policies', 200);

        $active = self::fields($catalog['active']);
        self::assertSame(1, $active['version']);
        self::assertSame(['EMPLOYEE_BENEFIT'], $active['cashExcludedAccountKinds']);
        self::assertSame('BUDGET_SURPLUS_OVER_CASH_INCOME', $active['savingsRateFormula']);
        self::assertSame('NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME', $active['netSavingsRateFormula']);
        self::assertTrue($active['system']);
        self::assertNull($active['createdAt']);
        self::assertNull($catalog['activeSince']);
        self::assertIsArray($catalog['versions']);
        self::assertCount(1, $catalog['versions']);
    }

    public function testTheMealVoucherMonthFollowsTheActivePolicy(): void
    {
        $this->seedVoucherMonth();

        $report = $this->call('GET', '/api/v1/reports/monthly?month='.$this->month, 200);
        // Hand-computed under version 1: 2000 salary stays cash, the 200 voucher
        // credit and 200 voucher spending leave the perimeter.
        self::assertSame('2000.00', self::metric($report, 'cashIncome'));
        self::assertSame('200.00', self::metric($report, 'nonCashBenefits'));
        self::assertSame('200.00', self::metric($report, 'benefitSpending'));
        self::assertSame('300.00', self::metric($report, 'budgetExpenses'));
        self::assertSame(['version' => 1, 'label' => 'Définition de trésorerie'], $report['metricPolicy']);

        $created = $this->call('POST', '/api/v1/reports/metric-policies', 201, ['label' => 'Tout compte', 'cashExcludedAccountKinds' => []]);
        self::assertSame(2, $created['version']);
        self::assertFalse($created['system']);
        $activated = $this->call('PUT', '/api/v1/reports/metric-policies/active', 200, ['version' => 2, 'expectedActiveVersion' => 1, 'reason' => 'Tout compter']);
        self::assertSame(2, self::fields($activated['active'])['version']);
        self::assertIsString($activated['activeSince']);

        $report = $this->call('GET', '/api/v1/reports/monthly?month='.$this->month, 200);
        self::assertSame('2200.00', self::metric($report, 'cashIncome'));
        self::assertSame('500.00', self::metric($report, 'budgetExpenses'));
        self::assertSame('0', self::metric($report, 'nonCashBenefits'));
        self::assertSame('0', self::metric($report, 'benefitSpending'));
        self::assertSame(['version' => 2, 'label' => 'Tout compte'], $report['metricPolicy']);
        $explained = $this->call('GET', '/api/v1/reports/monthly/cashIncome/explain?month='.$this->month, 200);
        self::assertSame(2, self::fields($explained['metricPolicy'])['version']);
        $recap = $this->call('GET', '/api/v1/reports/monthly/recap?month='.$this->month, 200);
        self::assertSame(2, self::fields($recap['metricPolicy'])['version']);

        $catalog = $this->call('GET', '/api/v1/reports/metric-policies', 200);
        self::assertSame(2, self::fields($catalog['active'])['version']);
        self::assertCount(2, self::list($catalog['versions']));
        self::assertSame(2, $this->connection->fetchOne("SELECT COUNT(*) FROM audit_events WHERE event_type IN ('metric_policy.created', 'metric_policy.activated')"));
    }

    public function testAClosedMonthKeepsItsVersionUntilItIsClosedAgain(): void
    {
        $this->seedVoucherMonth();
        $this->connection->insert('account_period_closures', [
            'id' => self::CLOSURE, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'year' => (int) $this->periodStart->format('Y'), 'month' => (int) $this->periodStart->format('n'),
            'closed_at' => '2020-01-01 10:00:00+00', 'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);
        $this->call('POST', '/api/v1/reports/metric-policies', 201, ['label' => 'Tout compte', 'cashExcludedAccountKinds' => []]);
        $this->call('PUT', '/api/v1/reports/metric-policies/active', 200, ['version' => 2, 'expectedActiveVersion' => 1]);

        $report = $this->call('GET', '/api/v1/reports/monthly?month='.$this->month, 200);
        self::assertSame(1, self::fields($report['metricPolicy'])['version']);
        self::assertSame('2000.00', self::metric($report, 'cashIncome'));

        $this->connection->executeStatement("UPDATE account_period_closures SET closed_at = now() + interval '1 minute'");
        $report = $this->call('GET', '/api/v1/reports/monthly?month='.$this->month, 200);
        self::assertSame(2, self::fields($report['metricPolicy'])['version']);
        self::assertSame('2200.00', self::metric($report, 'cashIncome'));
    }

    public function testAMonthWhoseOnlyIncomeIsOnAnExcludedKindHasNoSavingsRate(): void
    {
        $this->account(self::VOUCHER, WorkspaceFixture::OWN_WORKSPACE, 'EMPLOYEE_BENEFIT');
        $this->transaction('01', self::VOUCHER, '200.00', 'INCOME');

        $report = $this->call('GET', '/api/v1/reports/monthly?month='.$this->month, 200);

        self::assertSame('0', self::metric($report, 'cashIncome'));
        self::assertSame('200.00', self::metric($report, 'nonCashBenefits'));
        self::assertNull(self::fields($report['cashSavingsRate'])['value']);
        self::assertSame('ZERO_CASH_INCOME', self::fields($report['cashSavingsRate'])['reason']);
    }

    public function testAnActivationOfAnUnloadableVersionNeverFallsBackToVersionOne(): void
    {
        $this->seedVoucherMonth();
        $this->connection->insert('reporting_metric_policy_activations', [
            'id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'policy_version' => 7, 'active_from' => '2020-01-01 10:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID,
        ]);

        $report = $this->call('GET', '/api/v1/reports/monthly?month='.$this->month, 200);

        foreach (['cashIncome', 'nonCashBenefits', 'benefitSpending', 'budgetExpenses', 'uncategorizedExpenses', 'budgetSurplus', 'cashSavingsRate'] as $key) {
            self::assertNull(self::fields($report[$key])['value'], $key);
            self::assertSame('UNKNOWN_METRIC_POLICY', self::fields($report[$key])['reason'], $key);
        }
        self::assertSame(7, self::fields($report['metricPolicy'])['version']);

        $recap = $this->call('GET', '/api/v1/reports/monthly/recap?month='.$this->month, 200);
        $totals = self::fields($recap['totals']);
        $axes = self::list($totals['expensesByAxis']);
        $categories = self::list($totals['categories']);
        self::assertNotSame([], $axes);
        self::assertNotSame([], $categories);
        foreach ([...$axes, ...$categories] as $row) {
            $row = self::fields($row);
            self::assertNull($row['value']);
            self::assertSame('UNKNOWN_METRIC_POLICY', $row['reason']);
            self::assertSame([], $row['sourceTransactionIds']);
        }
    }

    public function testTheCatalogStaysReadableWhenTheActiveVersionCannotBeLoaded(): void
    {
        $this->connection->insert('reporting_metric_policy_activations', [
            'id' => '00000000-0000-7000-8000-0000000000e1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'policy_version' => 7, 'active_from' => '2020-01-01 10:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID,
        ]);
        $this->insertPolicy(WorkspaceFixture::OWN_WORKSPACE, 2, 'Illisible');
        $this->connection->executeStatement("UPDATE reporting_metric_policies SET cash_excluded_account_kinds = '[\"NOPE\"]'");

        $catalog = $this->call('GET', '/api/v1/reports/metric-policies', 200);

        self::assertNull($catalog['active']);
        self::assertSame(7, $catalog['activeVersion']);
        self::assertCount(1, self::list($catalog['versions']));

        // History and activation stay possible, and the undecodable row keeps its number.
        $created = $this->call('POST', '/api/v1/reports/metric-policies', 201, ['label' => 'Suivante', 'cashExcludedAccountKinds' => ['CASH']]);
        self::assertSame(3, $created['version']);
        $this->call('PUT', '/api/v1/reports/metric-policies/active', 422, ['version' => 2, 'expectedActiveVersion' => 7]);
        $activated = $this->call('PUT', '/api/v1/reports/metric-policies/active', 200, ['version' => 1, 'expectedActiveVersion' => 7]);
        self::assertSame(1, self::fields($activated['active'])['version']);
        $catalog = $this->call('GET', '/api/v1/reports/metric-policies', 200);
        self::assertSame(1, self::fields($catalog['active'])['version']);
        self::assertSame(1, $catalog['activeVersion']);
    }

    public function testAnActivationIsRefusedOnceTheHistoryReachesItsBound(): void
    {
        $this->call('POST', '/api/v1/reports/metric-policies', 201, ['label' => 'Tout compte', 'cashExcludedAccountKinds' => []]);
        $this->connection->executeStatement(
            "INSERT INTO reporting_metric_policy_activations (id, workspace_id, policy_version, active_from, created_by)
             SELECT gen_random_uuid(), :workspace, 1, timestamptz '2020-01-01 00:00:00+00' + (n || ' minutes')::interval, :author
             FROM generate_series(1, 5000) AS n",
            ['workspace' => WorkspaceFixture::OWN_WORKSPACE, 'author' => WorkspaceFixture::OWNER_ID],
        );

        $problem = $this->call('PUT', '/api/v1/reports/metric-policies/active', 409, ['version' => 2, 'expectedActiveVersion' => 1]);

        self::assertSame('activation_limit', $problem['code']);
        self::assertSame(5000, $this->connection->fetchOne('SELECT COUNT(*) FROM reporting_metric_policy_activations'));
    }

    public function testBudgetExplanationsNameTheCashPerimeter(): void
    {
        $this->seedVoucherMonth();

        foreach (['budgetExpenses', 'uncategorizedExpenses'] as $kpi) {
            $explained = $this->call('GET', '/api/v1/reports/monthly/'.$kpi.'/explain?month='.$this->month, 200);
            self::assertIsString($explained['formula']);
            self::assertIsString($explained['scope']);
            self::assertStringContainsString('inside the cash perimeter of the governing metric policy', $explained['formula'].' '.$explained['scope']);
        }
    }

    public function testDefinitionsAreUniqueValidatedAndBounded(): void
    {
        $this->call('POST', '/api/v1/reports/metric-policies', 201, ['label' => '  Sans épargne  ', 'cashExcludedAccountKinds' => ['SAVINGS', 'CASH']]);
        $catalog = $this->call('GET', '/api/v1/reports/metric-policies', 200);
        $second = self::fields(self::list($catalog['versions'])[1]);
        self::assertSame('Sans épargne', $second['label']);
        self::assertSame(['CASH', 'SAVINGS'], $second['cashExcludedAccountKinds']);

        $duplicate = $this->call('POST', '/api/v1/reports/metric-policies', 409, ['label' => 'Encore', 'cashExcludedAccountKinds' => ['CASH', 'SAVINGS']]);
        self::assertSame('policy_definition_exists', $duplicate['code']);
        self::assertSame(2, $duplicate['version']);
        $builtIn = $this->call('POST', '/api/v1/reports/metric-policies', 409, ['label' => 'Encore', 'cashExcludedAccountKinds' => ['EMPLOYEE_BENEFIT']]);
        self::assertSame(1, $builtIn['version']);

        foreach ([
            ['label' => '', 'cashExcludedAccountKinds' => []],
            ['label' => str_repeat('a', 81), 'cashExcludedAccountKinds' => []],
            ['label' => 'x', 'cashExcludedAccountKinds' => ['NOPE']],
            ['label' => 'x', 'cashExcludedAccountKinds' => ['CASH', 'CASH']],
            ['label' => 'x', 'cashExcludedAccountKinds' => ['CURRENT', 'SAVINGS', 'PORTFOLIO', 'INSURANCE_CONTRACT', 'EMPLOYEE_BENEFIT', 'CASH', 'REAL_ASSET', 'LIABILITY', 'CURRENT']],
            ['label' => 'x'],
            ['label' => 'x', 'cashExcludedAccountKinds' => [], 'version' => 9],
        ] as $body) {
            $this->call('POST', '/api/v1/reports/metric-policies', 422, $body);
        }
    }

    public function testAWorkspaceHoldsAtMostOneHundredVersions(): void
    {
        for ($version = 2; $version <= 101; ++$version) {
            $this->insertPolicy(WorkspaceFixture::OWN_WORKSPACE, $version, 'V'.$version);
        }

        $problem = $this->call('POST', '/api/v1/reports/metric-policies', 409, ['label' => 'Trop', 'cashExcludedAccountKinds' => ['CASH']]);

        self::assertSame('policy_version_limit', $problem['code']);
    }

    public function testActivationRejectsStaleUnknownAndAlreadyActiveVersions(): void
    {
        $this->call('POST', '/api/v1/reports/metric-policies', 201, ['label' => 'Tout compte', 'cashExcludedAccountKinds' => []]);

        $unknown = $this->call('PUT', '/api/v1/reports/metric-policies/active', 422, ['version' => 9, 'expectedActiveVersion' => 1]);
        self::assertSame('unknown_policy_version', $unknown['code']);
        $stale = $this->call('PUT', '/api/v1/reports/metric-policies/active', 409, ['version' => 2, 'expectedActiveVersion' => 2]);
        self::assertSame('stale_active_policy', $stale['code']);
        $already = $this->call('PUT', '/api/v1/reports/metric-policies/active', 409, ['version' => 1, 'expectedActiveVersion' => 1]);
        self::assertSame('policy_already_active', $already['code']);
        $this->call('PUT', '/api/v1/reports/metric-policies/active', 422, ['version' => 2, 'expectedActiveVersion' => 1, 'reason' => str_repeat('a', 201)]);
        $this->call('PUT', '/api/v1/reports/metric-policies/active', 422, ['version' => '2', 'expectedActiveVersion' => 1]);
    }

    public function testTwoActivationsReadingTheSameActiveVersionLetOnlyTheFirstWin(): void
    {
        $this->call('POST', '/api/v1/reports/metric-policies', 201, ['label' => 'Tout compte', 'cashExcludedAccountKinds' => []]);
        $this->call('POST', '/api/v1/reports/metric-policies', 201, ['label' => 'Sans espèces', 'cashExcludedAccountKinds' => ['CASH']]);

        $this->call('PUT', '/api/v1/reports/metric-policies/active', 200, ['version' => 2, 'expectedActiveVersion' => 1]);
        $loser = $this->call('PUT', '/api/v1/reports/metric-policies/active', 409, ['version' => 3, 'expectedActiveVersion' => 1]);

        self::assertSame('stale_active_policy', $loser['code']);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM reporting_metric_policy_activations'));
    }

    public function testAnotherWorkspaceNeverSeesOrActivatesAVersion(): void
    {
        $this->call('POST', '/api/v1/reports/metric-policies', 201, ['label' => 'Tout compte', 'cashExcludedAccountKinds' => []]);
        $this->call('PUT', '/api/v1/reports/metric-policies/active', 200, ['version' => 2, 'expectedActiveVersion' => 1]);
        $ownUnknown = $this->call('PUT', '/api/v1/reports/metric-policies/active', 422, ['version' => 9, 'expectedActiveVersion' => 2]);

        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $catalog = $this->call('GET', '/api/v1/reports/metric-policies', 200);
        self::assertSame(1, self::fields($catalog['active'])['version']);
        self::assertCount(1, self::list($catalog['versions']));
        self::assertStringNotContainsString('Tout compte', (string) $this->client->getResponse()->getContent());
        $foreign = $this->call('PUT', '/api/v1/reports/metric-policies/active', 422, ['version' => 2, 'expectedActiveVersion' => 1]);
        $missing = $this->call('PUT', '/api/v1/reports/metric-policies/active', 422, ['version' => 9, 'expectedActiveVersion' => 1]);
        self::assertSame($missing, $foreign);
        self::assertSame($ownUnknown, $missing);
    }

    public function testMutationsWithoutACsrfTokenAreRefused(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'forged');

        $this->client->request('POST', '/api/v1/reports/metric-policies', server: ['CONTENT_TYPE' => 'application/json'], content: '{"label":"x","cashExcludedAccountKinds":[]}');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('PUT', '/api/v1/reports/metric-policies/active', server: ['CONTENT_TYPE' => 'application/json'], content: '{"version":1,"expectedActiveVersion":1}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM reporting_metric_policies'));
    }

    private function seedVoucherMonth(): void
    {
        $this->connection->insert('category_categories', [
            'id' => '00000000-0000-7000-8000-0000000000c1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'type' => 'EXPENSE', 'label' => 'Budget',
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'CURRENT');
        $this->account(self::VOUCHER, WorkspaceFixture::OWN_WORKSPACE, 'EMPLOYEE_BENEFIT');
        $this->transaction('01', self::CURRENT, '2000.00', 'INCOME');
        $this->transaction('02', self::VOUCHER, '200.00', 'INCOME');
        $this->transaction('03', self::VOUCHER, '-200.00', 'EXPENSE');
        $this->transaction('04', self::CURRENT, '-300.00', 'EXPENSE');
    }

    private function insertPolicy(string $workspace, int $version, string $label): void
    {
        $this->connection->insert('reporting_metric_policies', [
            'id' => sprintf('00000000-0000-7000-8000-%012d', $version), 'workspace_id' => $workspace, 'version' => $version,
            'label' => $label, 'cash_excluded_account_kinds' => '[]',
            'savings_rate_formula' => 'BUDGET_SURPLUS_OVER_CASH_INCOME',
            'net_savings_rate_formula' => 'NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME',
            'created_at' => '2026-01-01 10:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID,
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

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, int $status, ?array $body = null): array
    {
        $this->client->request(
            $method,
            $uri,
            server: null === $body ? [] : ['CONTENT_TYPE' => 'application/json'],
            content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame($status);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return self::fields($data);
    }

    private function account(string $id, string $workspace, string $kind): void
    {
        $openedOn = $this->periodStart->modify('-1 year')->format('Y-m-d');
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $kind, 'asset_code' => 'EUR', 'kind' => $kind,
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => $openedOn, 'version' => 1,
            'created_at' => $openedOn.' 12:00:00+00', 'updated_at' => $openedOn.' 12:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function transaction(string $suffix, string $account, string $amount, string $nature): void
    {
        $bookedOn = $this->periodStart->modify('+14 days')->format('Y-m-d');
        $this->connection->insert('transaction_transactions', [
            'id' => '00000000-0000-7000-8000-0000000001'.$suffix, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'account_id' => $account, 'asset_code' => 'EUR', 'amount_value' => $amount, 'amount_scale' => 2,
            'state' => 'BOOKED', 'nature' => $nature, 'source' => 'MANUAL', 'booked_on' => $bookedOn,
            'raw_label' => 'Policy '.$suffix, 'version' => 1,
            'created_at' => $bookedOn.' 12:00:00+00', 'updated_at' => $bookedOn.' 12:00:00+00',
        ]);
    }

    /** @param array<string, mixed> $report */
    private static function metric(array $report, string $key): string
    {
        $metric = self::fields($report[$key]);
        self::assertIsString($metric['value']);

        return $metric['value'];
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }

    /** @return array<string, mixed> */
    private static function fields(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $result[$key] = $item;
        }

        return $result;
    }
}
