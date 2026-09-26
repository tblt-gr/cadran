<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BudgetMetricPolicyControllerTest extends WebTestCase
{
    private const string PLAN = '00000000-0000-7000-8000-0000000000b1';
    private const string TARGET = '00000000-0000-7000-8000-0000000000d1';
    private const string CURRENT = '00000000-0000-7000-8000-0000000000a1';
    private const string VOUCHER = '00000000-0000-7000-8000-0000000000a2';
    private const string CATEGORY = '00000000-0000-7000-8000-0000000000c1';

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
        $this->fixture = new WorkspaceFixture($connection);
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
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testAMonthlyRatioTargetResolvesAgainstTheCashPerimeterIncomeOfItsPolicy(): void
    {
        $this->plan('MONTH', '2026-09-01');
        $this->voucherMonth();
        $this->target('0.10');

        // Hand-computed under version 1: 10% of 2000.00 (the voucher credit is outside the perimeter).
        $comparisons = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons');
        self::assertSame(['version' => 1, 'label' => 'Définition de trésorerie'], $comparisons['metricPolicy']);
        $comparison = self::fields(self::list($comparisons['comparisons'])[0]);
        self::assertIsString($comparison['target']);
        self::assertMatchesRegularExpression('/^200(\.0+)?$/', $comparison['target']);
        self::assertSame('100.00', $comparison['actual']);

        $explained = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons/'.self::TARGET.'/explain');
        self::assertSame(['version' => 1, 'label' => 'Définition de trésorerie'], $explained['metricPolicy']);

        // Version 2 excludes no kind: the same ratio now resolves against 2200.00.
        $this->activateVersionTwo('2020-01-01 00:00:00+00');
        $comparisons = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons');
        self::assertSame(['version' => 2, 'label' => 'Tout compte'], $comparisons['metricPolicy']);
        $comparison = self::fields(self::list($comparisons['comparisons'])[0]);
        self::assertIsString($comparison['target']);
        self::assertMatchesRegularExpression('/^220(\.0+)?$/', $comparison['target']);
        self::assertSame('300.00', $comparison['actual']);
        $explained = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons/'.self::TARGET.'/explain');
        self::assertSame(2, self::fields($explained['metricPolicy'])['version']);
    }

    public function testAnAnnualPlanSpanningTwoPoliciesHasNoVersionAndNoResolvedRatio(): void
    {
        $this->plan('YEAR', '2026-01-01');
        $this->voucherMonth();
        $this->target('0.10');
        $this->activateVersionTwo('2026-05-10 08:00:00+00');
        $this->connection->insert('account_period_closures', [
            'id' => '00000000-0000-7000-8000-0000000000f1', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'year' => 2026, 'month' => 1, 'closed_at' => '2026-02-01 10:00:00+00',
            'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);

        $plan = $this->read('/api/v1/budget-plans/'.self::PLAN);

        self::assertSame(['version' => null, 'label' => null], $plan['metricPolicy']);
        $target = self::fields(self::list($plan['targets'])[0]);
        self::assertNull($target['resolvedAmount']);
        self::assertSame('MIXED_METRIC_POLICIES', $target['nonCalculableReason']);
    }

    private function plan(string $type, string $period): void
    {
        $this->connection->insert('budget_plans', [
            'id' => self::PLAN, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'period_type' => $type, 'period' => $period,
            'asset_code' => 'EUR', 'state' => 'ACTIVE', 'version' => 1,
            'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ]);
    }

    private function target(string $ratio): void
    {
        $this->connection->insert('budget_targets', [
            'id' => self::TARGET, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'plan_id' => self::PLAN,
            'scope_type' => 'CATEGORY', 'scope_id' => self::CATEGORY, 'value_type' => 'RATIO',
            'amount_value' => null, 'amount_scale' => null, 'ratio_value' => $ratio, 'ratio_scale' => 2,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ]);
    }

    private function activateVersionTwo(string $from): void
    {
        $this->connection->insert('reporting_metric_policies', [
            'id' => '00000000-0000-7000-8000-0000000000e2', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'version' => 2,
            'label' => 'Tout compte', 'cash_excluded_account_kinds' => '[]',
            'savings_rate_formula' => 'BUDGET_SURPLUS_OVER_CASH_INCOME',
            'net_savings_rate_formula' => 'NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME',
            'created_at' => '2020-01-01 00:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID,
        ]);
        $this->connection->insert('reporting_metric_policy_activations', [
            'id' => '00000000-0000-7000-8000-0000000000e3', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'policy_version' => 2, 'active_from' => $from, 'created_by' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    private function voucherMonth(): void
    {
        foreach ([[self::CURRENT, 'CURRENT'], [self::VOUCHER, 'EMPLOYEE_BENEFIT']] as [$id, $kind]) {
            $this->connection->insert('account_financial_accounts', [
                'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => $kind, 'asset_code' => 'EUR',
                'kind' => $kind, 'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE',
                'include_in_net_worth' => true, 'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01',
                'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
            ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
        }
        $this->connection->insert('category_categories', [
            'id' => self::CATEGORY, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'type' => 'EXPENSE', 'label' => 'Food',
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
        $this->transaction('01', self::CURRENT, '2000.00', 'INCOME');
        $this->transaction('02', self::VOUCHER, '200.00', 'INCOME');
        $this->transaction('03', self::CURRENT, '-100.00', 'EXPENSE', true);
        $this->transaction('04', self::VOUCHER, '-200.00', 'EXPENSE', true);
    }

    private function transaction(string $suffix, string $account, string $amount, string $nature, bool $split = false): void
    {
        $id = '00000000-0000-7000-8000-0000000001'.$suffix;
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => $account, 'asset_code' => 'EUR',
            'amount_value' => $amount, 'amount_scale' => 2, 'state' => 'BOOKED', 'nature' => $nature, 'source' => 'MANUAL',
            'booked_on' => '2026-09-15', 'raw_label' => 'Policy '.$suffix, 'version' => 1,
            'created_at' => '2026-09-15 12:00:00+00', 'updated_at' => '2026-09-15 12:00:00+00',
        ]);
        if ($split) {
            $this->connection->insert('transaction_splits', [
                'id' => '00000000-0000-7000-8000-0000000002'.$suffix, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'transaction_id' => $id, 'category_id' => self::CATEGORY, 'amount_value' => $amount, 'amount_scale' => 2,
                'asset_code' => 'EUR', 'analytic_axes' => '[]', 'created_at' => '2026-09-15 12:00:00+00',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function read(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return self::fields(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
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
