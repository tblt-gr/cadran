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

final class BudgetComparisonControllerTest extends WebTestCase
{
    private const string PLAN = '00000000-0000-7000-8000-0000000000b1';
    private const string EMPTY_PLAN = '00000000-0000-7000-8000-0000000000b2';
    private const string FOREIGN_PLAN = '00000000-0000-7000-8000-0000000000b9';
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000a1';
    private const string PARENT = '00000000-0000-7000-8000-0000000000c0';
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
        $this->signIn();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testItComparesClosedMonthlyTargetsUsingTheScopedReportingPolicy(): void
    {
        $this->plan(self::PLAN, WorkspaceFixture::OWN_WORKSPACE, 'CLOSED');
        $this->account();
        $this->category(self::PARENT, null, 1);
        $this->category(self::CATEGORY, self::PARENT, 2);
        $this->target('d1', 'CATEGORY', self::CATEGORY, 'AMOUNT', '150.00', 2, null, null);
        $this->target('d2', 'GROUP', self::PARENT, 'AMOUNT', '150.00', 2, null, null);
        $this->target('d3', 'AXIS', 'ESSENTIAL', 'RATIO', null, null, '0.20', 2);

        $this->transaction('01', '1000.00', 'INCOME');
        $this->transaction('02', '-125.00', 'EXPENSE', 'BOOKED', '-125.00', ['ESSENTIAL']);
        $this->transaction('03', '25.00', 'REFUND', 'BOOKED', '25.00', ['ESSENTIAL']);
        $this->transaction('04', '-30.00', 'EXPENSE', 'PENDING', '-30.00', ['ESSENTIAL']);
        $this->transaction('05', '-999.00', 'TRANSFER');
        $this->transaction('06', '-888.00', 'ADJUSTMENT');
        $this->closePeriod();

        $before = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons');
        $this->requestJson('PUT', '/api/v1/categories/'.self::CATEGORY, [
            'type' => 'EXPENSE', 'label' => 'Food', 'icon' => null, 'color' => null,
            'defaultAnalyticAxes' => [], 'budgetIncluded' => false, 'sortOrder' => 0, 'version' => 1,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        $this->requestJson('POST', '/api/v1/categories/'.self::CATEGORY.'/move', ['parentId' => null, 'version' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        $this->requestJson('PUT', '/api/v1/accounts/'.self::ACCOUNT, [
            'label' => 'EUR account', 'kind' => 'CURRENT', 'productCode' => null, 'productModelId' => null,
            'institution' => null, 'maskedIdentifier' => null, 'valuationMode' => 'TRANSACTIONS',
            'liquidityLevel' => 'IMMEDIATE', 'includeInNetWorth' => false, 'includeInEmergencyFund' => false,
            'openedOn' => '2026-10-01', 'closedOn' => null, 'primaryGroupId' => null, 'tagGroupIds' => [],
            'version' => 1,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        $response = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons');
        self::assertSame($before, $response);

        self::assertSame('AVAILABLE', $response['status']);
        self::assertNull($response['reason']);
        /** @var list<array<string, mixed>> $comparisons */
        $comparisons = $response['comparisons'];
        self::assertCount(3, $comparisons);
        foreach ($comparisons as $comparison) {
            self::assertSame('100.00', $comparison['actual']);
            self::assertNull($comparison['actualReason']);
            self::assertSame(1, $comparison['pendingCount']);
            self::assertSame([
                [
                    'id' => '00000000-0000-7000-8000-000000000102',
                    'bookedOn' => '2026-09-15',
                    'rawLabel' => 'Budget 02',
                    'amount' => '-125.00',
                    'assetCode' => 'EUR',
                ],
                [
                    'id' => '00000000-0000-7000-8000-000000000103',
                    'bookedOn' => '2026-09-15',
                    'rawLabel' => 'Budget 03',
                    'amount' => '25.00',
                    'assetCode' => 'EUR',
                ],
            ], $comparison['includedTransactions']);
            self::assertArrayNotHasKey('includedTransactionIds', $comparison);
            self::assertIsString($comparison['policy']);
            self::assertStringContainsString('BOOKED', $comparison['policy']);
            self::assertArrayNotHasKey('policyId', $comparison);
        }
        self::assertSame('150.00', $comparisons[0]['target']);
        self::assertSame('50.00', $comparisons[0]['variance']);
        self::assertSame('WITHIN_TARGET', $comparisons[0]['status']);
        self::assertSame('200.0000', $comparisons[2]['target']);
        self::assertSame('100.0000', $comparisons[2]['variance']);
    }

    public function testMissingTargetsAndForeignPlansHaveExplicitSafeResults(): void
    {
        $this->plan(self::EMPTY_PLAN, WorkspaceFixture::OWN_WORKSPACE, 'DRAFT');
        $this->plan(self::FOREIGN_PLAN, WorkspaceFixture::OTHER_WORKSPACE, 'DRAFT');

        $empty = $this->read('/api/v1/budget-plans/'.self::EMPTY_PLAN.'/comparisons');
        self::assertSame('NO_TARGETS', $empty['status']);
        self::assertSame('MISSING_TARGET', $empty['reason']);
        self::assertSame([], $empty['comparisons']);

        $this->client->request('GET', '/api/v1/budget-plans/'.self::FOREIGN_PLAN.'/comparisons');
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/api/v1/budget-plans/00000000-0000-7000-8000-0000000000e8/comparisons');
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());
    }

    public function testAZeroIncomeRatioAndANonCalculableActualNeverBecomeDefaultZeroes(): void
    {
        $this->plan(self::PLAN, WorkspaceFixture::OWN_WORKSPACE, 'ACTIVE');
        $this->account();
        $this->category(self::PARENT, null, 1);
        $this->category(self::CATEGORY, self::PARENT, 2);
        $this->target('d1', 'CATEGORY', self::CATEGORY, 'RATIO', null, null, '0.25', 2);

        $response = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons');
        $comparisons = $response['comparisons'];
        self::assertIsArray($comparisons);
        /* @var list<array<string, mixed>> $comparisons */
        self::assertIsArray($comparisons[0]);
        self::assertSame('0', $comparisons[0]['actual']);
        self::assertNull($comparisons[0]['actualReason']);
        self::assertNull($comparisons[0]['target']);
        self::assertSame('ZERO_CASH_INCOME', $comparisons[0]['targetReason']);
        self::assertNull($comparisons[0]['variance']);
        self::assertSame('NON_CALCULABLE', $comparisons[0]['status']);
    }

    public function testAnEmptyScopeUsesThePlanAssetInsteadOfInventingAMissingActual(): void
    {
        $this->plan(self::PLAN, WorkspaceFixture::OWN_WORKSPACE, 'ACTIVE');
        $this->account();
        $this->category(self::PARENT, null, 1);
        $this->category(self::CATEGORY, self::PARENT, 2);
        $this->target('d1', 'CATEGORY', self::CATEGORY, 'AMOUNT', '100.00', 2, null, null);

        $response = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons');
        $comparisons = $response['comparisons'];
        self::assertIsArray($comparisons);
        /* @var list<array<string, mixed>> $comparisons */
        self::assertIsArray($comparisons[0]);
        self::assertSame('0', $comparisons[0]['actual']);
        self::assertNull($comparisons[0]['actualReason']);
        self::assertSame('100.00', $comparisons[0]['target']);
        self::assertNull($comparisons[0]['targetReason']);
        self::assertSame('100.00', $comparisons[0]['variance']);
        self::assertSame('WITHIN_TARGET', $comparisons[0]['status']);
    }

    public function testNoAccountMakesTheActualExplicitlyNonCalculable(): void
    {
        $this->plan(self::PLAN, WorkspaceFixture::OWN_WORKSPACE, 'ACTIVE');
        $this->category(self::PARENT, null, 1);
        $this->category(self::CATEGORY, self::PARENT, 2);
        $this->target('d1', 'CATEGORY', self::CATEGORY, 'AMOUNT', '100.00', 2, null, null);

        $response = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons');
        $comparisons = $response['comparisons'];
        self::assertIsArray($comparisons);
        /* @var list<array<string, mixed>> $comparisons */
        self::assertIsArray($comparisons[0]);
        self::assertNull($comparisons[0]['actual']);
        self::assertSame('NO_ACCOUNT', $comparisons[0]['actualReason']);
        self::assertSame('100.00', $comparisons[0]['target']);
        self::assertNull($comparisons[0]['variance']);
        self::assertSame('NON_CALCULABLE', $comparisons[0]['status']);
    }

    public function testLargeBookedIncomeStaysExactAtTheEndpoint(): void
    {
        $this->plan(self::PLAN, WorkspaceFixture::OWN_WORKSPACE, 'ACTIVE');
        $this->account();
        $this->category(self::PARENT, null, 1);
        $this->category(self::CATEGORY, self::PARENT, 2);
        $this->target('d1', 'CATEGORY', self::CATEGORY, 'RATIO', null, null, '0.50', 2);
        $maximum = '99999999999999999999999999.999999999999999999999999';
        $this->transaction('01', $maximum, 'INCOME', amountScale: 24);
        $this->transaction('02', $maximum, 'INCOME', amountScale: 24);

        $response = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons');
        $comparisons = $response['comparisons'];
        self::assertIsArray($comparisons);
        /* @var list<array<string, mixed>> $comparisons */
        self::assertIsArray($comparisons[0]);
        self::assertSame('99999999999999999999999999.999999999999999999999999', $comparisons[0]['target']);
        self::assertSame('99999999999999999999999999.999999999999999999999999', $comparisons[0]['variance']);
        self::assertSame('WITHIN_TARGET', $comparisons[0]['status']);
    }

    public function testAnUnrelatedAccountAssetDoesNotPoisonAScopedActual(): void
    {
        $this->plan(self::PLAN, WorkspaceFixture::OWN_WORKSPACE, 'ACTIVE');
        $this->account();
        $this->account('00000000-0000-7000-8000-0000000000a2', 'USD');
        $this->category(self::PARENT, null, 1);
        $this->category(self::CATEGORY, self::PARENT, 2);
        $this->target('d1', 'CATEGORY', self::CATEGORY, 'AMOUNT', '100.00', 2, null, null);
        $this->transaction('01', '-25.00', 'EXPENSE', 'BOOKED', '-25.00', ['ESSENTIAL']);

        $response = $this->read('/api/v1/budget-plans/'.self::PLAN.'/comparisons');
        $comparisons = $response['comparisons'];
        self::assertIsArray($comparisons);
        self::assertIsArray($comparisons[0]);
        self::assertSame('25.00', $comparisons[0]['actual']);
        self::assertNull($comparisons[0]['actualReason']);
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @param array<string, mixed> $body */
    private function requestJson(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /** @return array<string, mixed> */
    private function read(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    private function plan(string $id, string $workspace, string $state): void
    {
        $this->connection->insert('budget_plans', [
            'id' => $id, 'workspace_id' => $workspace, 'period_type' => 'MONTH', 'period' => '2026-09-01',
            'asset_code' => 'EUR', 'state' => $state, 'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00',
        ]);
    }

    private function account(string $id = self::ACCOUNT, string $asset = 'EUR'): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => $asset.' account',
            'asset_code' => $asset, 'kind' => 'CURRENT', 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'version' => 1,
            'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function closePeriod(): void
    {
        $this->connection->insert('account_period_closures', [
            'id' => '00000000-0000-7000-8000-0000000000f1',
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'year' => 2026,
            'month' => 9,
            'closed_at' => '2026-10-01 12:00:00+00',
            'closed_by' => WorkspaceFixture::OWNER_ID,
            'reopened_at' => null,
            'reopen_reason' => null,
            'version' => 1,
        ]);
    }

    private function category(string $id, ?string $parentId, int $depth): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'type' => 'EXPENSE',
            'label' => null === $parentId ? 'Living' : 'Food', 'parent_id' => $parentId,
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => $depth,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
    }

    private function target(
        string $suffix,
        string $scopeType,
        string $scopeId,
        string $valueType,
        ?string $amount,
        ?int $amountScale,
        ?string $ratio,
        ?int $ratioScale,
    ): void {
        $this->connection->insert('budget_targets', [
            'id' => '00000000-0000-7000-8000-0000000000'.$suffix,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'plan_id' => self::PLAN,
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'value_type' => $valueType,
            'amount_value' => $amount, 'amount_scale' => $amountScale, 'ratio_value' => $ratio, 'ratio_scale' => $ratioScale,
            'version' => 1, 'created_at' => '2026-09-01 12:00:0'.$suffix[1].'+00', 'updated_at' => '2026-09-01 12:00:00+00',
        ]);
    }

    /** @param list<string> $axes */
    private function transaction(
        string $suffix,
        string $amount,
        string $nature,
        string $state = 'BOOKED',
        ?string $splitAmount = null,
        array $axes = [],
        int $amountScale = 2,
    ): void {
        $id = '00000000-0000-7000-8000-0000000001'.$suffix;
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::ACCOUNT,
            'asset_code' => 'EUR', 'amount_value' => $amount, 'amount_scale' => $amountScale, 'state' => $state,
            'nature' => $nature, 'source' => 'MANUAL', 'booked_on' => '2026-09-15', 'raw_label' => 'Budget '.$suffix,
            'version' => 1, 'created_at' => '2026-09-15 12:00:00+00', 'updated_at' => '2026-09-15 12:00:00+00',
        ]);
        if (null !== $splitAmount) {
            $this->connection->insert('transaction_splits', [
                'id' => '00000000-0000-7000-8000-0000000002'.$suffix,
                'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'transaction_id' => $id,
                'category_id' => self::CATEGORY, 'amount_value' => $splitAmount, 'amount_scale' => 2,
                'asset_code' => 'EUR', 'analytic_axes' => json_encode($axes, JSON_THROW_ON_ERROR),
                'created_at' => '2026-09-15 12:00:00+00',
            ]);
        }
    }
}
