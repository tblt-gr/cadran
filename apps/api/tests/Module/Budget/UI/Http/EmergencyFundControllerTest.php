<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\UI\Http;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class EmergencyFundControllerTest extends WebTestCase
{
    private const string TODAY = '2026-09-29T09:12:04+00:00';
    private const string POLICY = '/api/v1/goals/emergency-fund/policy';
    private const string COVERAGE = '/api/v1/goals/emergency-fund';
    private const string OWN_CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string INCOME_CATEGORY = '00000000-0000-7000-8000-0000000000c2';
    private const string ARCHIVED_CATEGORY = '00000000-0000-7000-8000-0000000000c3';
    private const string UNBUDGETED_CATEGORY = '00000000-0000-7000-8000-0000000000c4';
    private const string FOREIGN_CATEGORY = '00000000-0000-7000-8000-0000000000c9';
    private const string CHILD_CATEGORY = '00000000-0000-7000-8000-0000000000c5';
    private const string LEISURE_CATEGORY = '00000000-0000-7000-8000-0000000000c6';
    private const string CURRENT_ACCOUNT = '00000000-0000-7000-8000-0000000000d7';

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
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->requestJson('POST', '/api/v1/session', ['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);

        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        self::getContainer()->set(ClockInterface::class, new MockClock(new \DateTimeImmutable(self::TODAY)));
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

    public function testAWorkspaceWithoutARowReadsTheDefaultPolicy(): void
    {
        $this->client->request('GET', self::POLICY);

        self::assertResponseIsSuccessful();
        self::assertSame(['essentialMode' => 'AXIS', 'essentialCategories' => [], 'referenceMode' => 'PREVIOUS_YEAR', 'trailingMonths' => null, 'targetMonths' => '6', 'version' => 0, 'targetMode' => 'MONTHS', 'targetAmount' => null, 'targetRatio' => null], $this->decode());
    }

    public function testAPolicyIsInsertedAtVersionZeroThenRevisedAndAuditedByFieldNameOnly(): void
    {
        $this->insertCategory(self::OWN_CATEGORY, WorkspaceFixture::OWN_WORKSPACE);

        $this->requestJson('PUT', self::POLICY, $this->policy([self::OWN_CATEGORY], 'TRAILING_MONTHS', 6, '4.5', 0));
        self::assertResponseIsSuccessful();
        $saved = $this->decode();
        self::assertSame(1, $saved['version']);
        self::assertSame([['id' => self::OWN_CATEGORY, 'label' => 'Essentials c1', 'archived' => false]], $saved['essentialCategories']);
        self::assertSame('4.5', $saved['targetMonths']);
        $this->client->request('GET', self::POLICY);
        self::assertSame($saved, $this->decode());

        $this->requestJson('PUT', self::POLICY, ['essentialMode' => 'AXIS', 'essentialCategoryIds' => [], 'referenceMode' => 'TRAILING_MONTHS', 'trailingMonths' => 6, 'targetMonths' => '9', 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode()['version']);

        $events = $this->connection->fetchAllAssociative('SELECT event_type, entity_type, after_json::text AS after FROM audit_events WHERE workspace_id = ? AND entity_type = ? ORDER BY occurred_at', [WorkspaceFixture::OWN_WORKSPACE, 'emergency_fund_policy']);
        self::assertSame(['emergency_fund_policy.updated', 'emergency_fund_policy.updated'], array_column($events, 'event_type'));
        $trail = implode('', array_map(static fn (mixed $after): string => is_string($after) ? $after : '', array_column($events, 'after')));
        self::assertStringContainsString('targetMonths', $trail);
        self::assertStringNotContainsString('4.5', $trail);
        self::assertStringNotContainsString('Essentials', $trail);
        self::assertStringNotContainsString(self::OWN_CATEGORY, $trail);
    }

    public function testTheTargetCanBeAFixedAmountOrAnIncomeRatioAndIncoherentOnesAreRefused(): void
    {
        $base = $this->axisPolicy('6', 0);
        $this->requestJson('PUT', self::POLICY, [...$base, 'targetMode' => 'AMOUNT', 'targetAmount' => ['value' => '15000', 'assetCode' => 'EUR'], 'targetRatio' => null]);
        self::assertResponseIsSuccessful();
        $saved = $this->decode();
        self::assertSame(['AMOUNT', ['value' => '15000', 'assetCode' => 'EUR'], null], [$saved['targetMode'], $saved['targetAmount'], $saved['targetRatio']]);

        $this->requestJson('PUT', self::POLICY, [...$base, 'version' => 1, 'targetMode' => 'INCOME_RATIO', 'targetAmount' => null, 'targetRatio' => '0.5']);
        self::assertResponseIsSuccessful();
        self::assertSame(['INCOME_RATIO', null, '0.5'], [$this->decode()['targetMode'], $this->decode()['targetAmount'], $this->decode()['targetRatio']]);

        foreach ([
            [['targetMode' => 'INCOME_RATIO', 'targetAmount' => null, 'targetRatio' => '1.5'], 'invalid_target_ratio'],
            [['targetMode' => 'AMOUNT', 'targetAmount' => ['value' => '0', 'assetCode' => 'EUR'], 'targetRatio' => null], 'invalid_target_amount'],
            [['targetMode' => 'AMOUNT', 'targetAmount' => ['value' => '10', 'assetCode' => 'eur'], 'targetRatio' => null], 'invalid_target_amount'],
            [['targetMode' => 'SOMETHING', 'targetAmount' => null, 'targetRatio' => null], 'invalid_emergency_fund_policy'],
        ] as [$target, $code]) {
            $this->requestJson('PUT', self::POLICY, [...$base, 'version' => 2, ...$target]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($code, $this->decode()['code']);
        }
    }

    public function testMutationsRequireCsrfAndAStaleVersionConflicts(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->requestJson('PUT', self::POLICY, $this->axisPolicy('6', 0));
        self::assertResponseStatusCodeSame(403);
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());

        $this->requestJson('PUT', self::POLICY, $this->axisPolicy('3', 0));
        self::assertResponseIsSuccessful();
        $this->requestJson('PUT', self::POLICY, $this->axisPolicy('12', 0));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_emergency_fund_policy', $this->decode()['code']);
        $this->requestJson('PUT', self::POLICY, $this->axisPolicy('12', 7));
        self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', self::POLICY);
        self::assertSame('3', $this->decode()['targetMonths']);
    }

    public function testReferenceSevenAForeignCategoryAnswersExactlyLikeAnUnknownOneAsDoIncomeAndArchivedCategories(): void
    {
        $this->insertCategory(self::FOREIGN_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE);
        $this->insertCategory(self::INCOME_CATEGORY, WorkspaceFixture::OWN_WORKSPACE, 'INCOME');
        $this->insertCategory(self::ARCHIVED_CATEGORY, WorkspaceFixture::OWN_WORKSPACE, archivedAt: '2026-09-10 12:00:00+00');
        $this->insertCategory(self::UNBUDGETED_CATEGORY, WorkspaceFixture::OWN_WORKSPACE, budgetIncluded: false);

        $this->requestJson('PUT', self::POLICY, $this->policy([self::FOREIGN_CATEGORY], 'PREVIOUS_YEAR', null, '6', 0));
        self::assertResponseStatusCodeSame(422);
        $foreign = (string) $this->client->getResponse()->getContent();
        self::assertSame('invalid_emergency_fund_category', $this->decode()['code']);
        foreach (['00000000-0000-7000-8000-0000000000c8', self::INCOME_CATEGORY, self::ARCHIVED_CATEGORY, self::UNBUDGETED_CATEGORY, 'not-a-uuid'] as $id) {
            $this->requestJson('PUT', self::POLICY, $this->policy([$id], 'PREVIOUS_YEAR', null, '6', 0));
            self::assertResponseStatusCodeSame(422);
            self::assertSame($foreign, (string) $this->client->getResponse()->getContent());
        }
        self::assertSame(0, $this->storedPolicies());
    }

    public function testACategoryArchivedAfterBeingListedMayStayListed(): void
    {
        $this->insertCategory(self::OWN_CATEGORY, WorkspaceFixture::OWN_WORKSPACE);
        $this->requestJson('PUT', self::POLICY, $this->policy([self::OWN_CATEGORY], 'PREVIOUS_YEAR', null, '6', 0));
        self::assertResponseIsSuccessful();
        $this->connection->update('category_categories', ['archived_at' => '2026-09-20 12:00:00+00', 'version' => 2], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => self::OWN_CATEGORY]);

        $this->requestJson('PUT', self::POLICY, $this->policy([self::OWN_CATEGORY], 'PREVIOUS_YEAR', null, '8', 1));

        self::assertResponseIsSuccessful();
        self::assertSame([['id' => self::OWN_CATEGORY, 'label' => 'Essentials c1', 'archived' => true]], $this->decode()['essentialCategories']);
    }

    public function testOutOfRangeFiguresAndMalformedBodiesAreRefusedWithoutStoringAnything(): void
    {
        $tooMany = array_map(static fn (int $index): string => sprintf('00000000-0000-7000-8000-%012d', $index), range(1, 51));
        foreach ([
            [$this->policy([], 'TRAILING_MONTHS', 25, '6', 0), 'invalid_trailing_months'],
            [$this->policy([], 'TRAILING_MONTHS', 0, '6', 0), 'invalid_trailing_months'],
            [$this->axisPolicy('60.5', 0), 'invalid_target_months'],
            [$this->axisPolicy('6.25', 0), 'invalid_target_months'],
            [$this->axisPolicy('-1', 0), 'invalid_target_months'],
            [$this->axisPolicy('6,5', 0), 'invalid_target_months'],
            [$this->policy($tooMany, 'PREVIOUS_YEAR', null, '6', 0), 'too_many_emergency_fund_categories'],
            [[...$this->axisPolicy('6', 0), 'extra' => true], 'invalid_emergency_fund_policy'],
            [[...$this->axisPolicy('6', 0), 'essentialMode' => 'SOMETIMES'], 'invalid_emergency_fund_policy'],
            [[...$this->axisPolicy('6', 0), 'targetMonths' => 6], 'invalid_emergency_fund_policy'],
        ] as [$body, $code]) {
            $this->requestJson('PUT', self::POLICY, $body);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($code, $this->decode()['code']);
        }
        self::assertSame(0, $this->storedPolicies());
    }

    public function testReferenceSevenAnotherWorkspacesPolicyIsNeverReadNorWritten(): void
    {
        $this->connection->insert('budget_emergency_fund_policies', ['workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'essential_mode' => 'AXIS', 'essential_category_ids' => '[]', 'reference_mode' => 'TRAILING_MONTHS', 'trailing_months' => 3, 'target_months' => '12', 'version' => 4, 'updated_at' => '2026-09-01 12:00:00+00', 'updated_by' => WorkspaceFixture::OTHER_OWNER_ID]);

        $this->client->request('GET', self::POLICY);
        self::assertSame(0, $this->decode()['version']);
        self::assertSame('6', $this->decode()['targetMonths']);
        $this->requestJson('PUT', self::POLICY, $this->axisPolicy('2', 4));
        self::assertResponseStatusCodeSame(409);
        $this->requestJson('PUT', self::POLICY, $this->axisPolicy('2', 0));
        self::assertResponseIsSuccessful();

        self::assertSame([true, 4], array_values(self::arr($this->connection->fetchAssociative('SELECT target_months = 12 AS untouched, version FROM budget_emergency_fund_policies WHERE workspace_id = ?', [WorkspaceFixture::OTHER_WORKSPACE]))));
    }

    public function testCoverageListsEligibleAccountsFlagsAStaleValuationAndNeverReadsAnotherWorkspace(): void
    {
        $current = '00000000-0000-7000-8000-0000000000d1';
        $stale = '00000000-0000-7000-8000-0000000000d2';
        $ineligible = '00000000-0000-7000-8000-0000000000d3';
        $foreign = '00000000-0000-7000-8000-0000000000d9';
        $this->insertAccount($current, WorkspaceFixture::OWN_WORKSPACE, true);
        $this->insertAccount($stale, WorkspaceFixture::OWN_WORKSPACE, true);
        $this->insertAccount($ineligible, WorkspaceFixture::OWN_WORKSPACE, false);
        $this->insertAccount($foreign, WorkspaceFixture::OTHER_WORKSPACE, true);
        $this->snapshot($current, WorkspaceFixture::OWN_WORKSPACE, '2026-09-29', '3000.00');
        $this->snapshot($stale, WorkspaceFixture::OWN_WORKSPACE, '2026-08-15', '9600.00');
        $this->snapshot($ineligible, WorkspaceFixture::OWN_WORKSPACE, '2026-09-29', '50000.00');
        $this->snapshot($foreign, WorkspaceFixture::OTHER_WORKSPACE, '2026-09-29', '70000.00');

        $this->client->request('GET', self::COVERAGE);
        self::assertResponseIsSuccessful();
        $coverage = $this->decode();
        self::assertSame('2026-09-29', $coverage['asOf']);
        $liquid = self::arr($coverage['liquidAssets']);
        self::assertSame('12600.00', $liquid['value']);
        self::assertSame('EUR', $liquid['assetCode']);
        self::assertSame([
            ['id' => $current, 'label' => 'Livret d1', 'value' => '3000.00', 'assetCode' => 'EUR', 'valuedOn' => '2026-09-29', 'stale' => false],
            ['id' => $stale, 'label' => 'Livret d2', 'value' => '9600.00', 'assetCode' => 'EUR', 'valuedOn' => '2026-08-15', 'stale' => true],
        ], $liquid['accounts']);
        $reference = self::arr($coverage['reference']);
        self::assertSame(['2025-01', '2025-12', 0, 12, null, 'NO_REFERENCE_MONTH', null], [$reference['start'], $reference['end'], $reference['usedMonths'], $reference['expectedMonths'], $reference['average'], $reference['averageReason'], $reference['assetCode']]);
        self::assertCount(12, self::arr($reference['excludedMonths']));
        self::assertSame('6', $coverage['targetMonths']);
        self::assertNull($coverage['requiredAmount']);
        self::assertNull($coverage['gap']);
        self::assertNull($coverage['coveredMonths']);
        self::assertSame('NO_REFERENCE_MONTH', $coverage['reason']);
        self::assertSame('NOT_CALCULABLE', $coverage['quality']);
    }

    /**
     * Twelve months of 2025, each with 1500.00 on a parent category and 600.00 on its child, both
     * essential, and 400.00 of discretionary leisure: the essential average is 2100.00 by the axis
     * and by the parent category alike, the child never counted twice. 12600.00 / 2100 = 6.
     */
    public function testReferenceOneAndSixFromBookedTransactionsInAxisAndCategoryModes(): void
    {
        $savings = '00000000-0000-7000-8000-0000000000d1';
        $this->insertAccount(self::CURRENT_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, false);
        $this->insertAccount($savings, WorkspaceFixture::OWN_WORKSPACE, true);
        $this->snapshot($savings, WorkspaceFixture::OWN_WORKSPACE, '2026-09-29', '12600.00');
        $this->insertCategory(self::OWN_CATEGORY, WorkspaceFixture::OWN_WORKSPACE);
        $this->insertCategory(self::CHILD_CATEGORY, WorkspaceFixture::OWN_WORKSPACE, parentId: self::OWN_CATEGORY);
        $this->insertCategory(self::LEISURE_CATEGORY, WorkspaceFixture::OWN_WORKSPACE);
        $sequence = 0;
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('2025-%02d-10', $month);
            $this->expense(++$sequence, $day, '1500.00', self::OWN_CATEGORY, 'ESSENTIAL');
            $this->expense(++$sequence, $day, '600.00', self::CHILD_CATEGORY, 'ESSENTIAL');
            $this->expense(++$sequence, $day, '400.00', self::LEISURE_CATEGORY, 'DISCRETIONARY');
        }

        foreach ([null, [self::OWN_CATEGORY, self::CHILD_CATEGORY]] as $version => $categories) {
            if (null !== $categories) {
                $this->requestJson('PUT', self::POLICY, $this->policy($categories, 'PREVIOUS_YEAR', null, '6', 0));
                self::assertResponseIsSuccessful();
            }
            $this->client->request('GET', self::COVERAGE);
            self::assertResponseIsSuccessful();
            $coverage = $this->decode();
            $reference = self::arr($coverage['reference']);
            self::assertSame([12, 12, 'EUR'], [$reference['usedMonths'], $reference['expectedMonths'], $reference['assetCode']], 'policy '.$version);
            self::assertDecimal('2100', $reference['average']);
            self::assertSame('6.0', $coverage['coveredMonthsDisplay']);
            self::assertDecimal('12600', $coverage['requiredAmount']);
            self::assertDecimal('0', $coverage['gap']);
            self::assertSame('COMPLETE', $coverage['quality']);
        }
    }

    public function testAnOversizedWorkspaceAnswersADocumentedScopeProblem(): void
    {
        for ($index = 1; $index <= 501; ++$index) {
            $this->insertAccount(sprintf('00000000-0000-7000-8000-%012d', 900000 + $index), WorkspaceFixture::OWN_WORKSPACE, true);
        }

        $this->client->request('GET', self::COVERAGE);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('emergency_fund_scope_too_large', $this->decode()['code']);
    }

    public function testAnEligibleAccountWithoutValuationMakesLiquidAssetsUnknown(): void
    {
        $valued = '00000000-0000-7000-8000-0000000000d1';
        $unvalued = '00000000-0000-7000-8000-0000000000d2';
        $this->insertAccount($valued, WorkspaceFixture::OWN_WORKSPACE, true);
        $this->insertAccount($unvalued, WorkspaceFixture::OWN_WORKSPACE, true);
        $this->snapshot($valued, WorkspaceFixture::OWN_WORKSPACE, '2026-09-29', '3000.00');

        $this->client->request('GET', self::COVERAGE.'?asOf=2026-09-29');
        self::assertResponseIsSuccessful();
        $liquid = self::arr($this->decode()['liquidAssets']);
        self::assertNull($liquid['value']);
        self::assertSame('MISSING_VALUATION', $liquid['reason']);
        self::assertNull(self::arr(array_values(self::arr($liquid['accounts']))[1])['value']);
    }

    public function testAFutureOrMalformedAsOfDateIsRejected(): void
    {
        $this->client->request('GET', self::COVERAGE.'?asOf=2026-09-30');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('future_emergency_fund_as_of', $this->decode()['code']);
        $this->client->request('GET', self::COVERAGE.'?asOf=2026-9-1');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_emergency_fund_as_of', $this->decode()['code']);
    }

    /** @return array<string, mixed> */
    private function axisPolicy(string $target, int $version): array
    {
        return $this->policy([], 'PREVIOUS_YEAR', null, $target, $version);
    }

    /**
     * @param list<string> $categories
     *
     * @return array<string, mixed>
     */
    private function policy(array $categories, string $reference, ?int $trailing, string $target, int $version): array
    {
        return ['essentialMode' => [] === $categories ? 'AXIS' : 'CATEGORIES', 'essentialCategoryIds' => $categories, 'referenceMode' => $reference, 'trailingMonths' => $trailing, 'targetMonths' => $target, 'version' => $version];
    }

    private function insertCategory(string $id, string $workspace, string $type = 'EXPENSE', ?string $archivedAt = null, bool $budgetIncluded = true, ?string $parentId = null): void
    {
        $this->connection->insert('category_categories', ['id' => $id, 'workspace_id' => $workspace, 'type' => $type, 'label' => 'Essentials '.substr($id, -2), 'parent_id' => $parentId, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => $budgetIncluded, 'sort_order' => 0, 'depth' => null === $parentId ? 1 : 2, 'version' => 1, 'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00', 'archived_at' => $archivedAt], ['budget_included' => ParameterType::BOOLEAN]);
    }

    private function insertAccount(string $id, string $workspace, bool $eligible): void
    {
        $this->connection->insert('account_financial_accounts', ['id' => $id, 'workspace_id' => $workspace, 'label' => 'Livret '.ltrim(substr($id, -6), '0'), 'asset_code' => 'EUR', 'kind' => 'SAVINGS', 'masked_identifier' => null, 'valuation_mode' => 'SNAPSHOTS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true, 'include_in_emergency_fund' => $eligible, 'opened_on' => '2020-01-01', 'closed_on' => null, 'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00', 'used_at' => null, 'archived_at' => null], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function snapshot(string $account, string $workspace, string $day, string $amount): void
    {
        $this->connection->insert('account_balance_snapshots', [
            'id' => substr(sha1($workspace.$account.$day), 0, 8).'-0000-7000-8000-000000000000',
            'workspace_id' => $workspace, 'account_id' => $account, 'as_of' => $day, 'amount_value' => $amount,
            'amount_literal' => $amount, 'amount_asset' => 'EUR', 'source' => 'MANUAL', 'reconciliation_status' => 'UNRECONCILED',
            'version' => 1, 'recorded_at' => $day.' 12:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    private function expense(int $sequence, string $day, string $amount, string $category, string $axis): void
    {
        $id = sprintf('00000000-0000-7000-8000-%012d', 100000 + $sequence);
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::CURRENT_ACCOUNT, 'asset_code' => 'EUR',
            'amount_value' => '-'.$amount, 'amount_scale' => 2, 'state' => 'BOOKED', 'nature' => 'EXPENSE',
            'source' => 'MANUAL', 'booked_on' => $day, 'raw_label' => 'Essential '.$sequence,
            'version' => 1, 'created_at' => $day.' 12:00:00+00', 'updated_at' => $day.' 12:00:00+00',
        ]);
        $this->connection->insert('transaction_splits', [
            'id' => sprintf('00000000-0000-7000-8000-%012d', 200000 + $sequence), 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'transaction_id' => $id, 'category_id' => $category, 'amount_value' => '-'.$amount, 'amount_scale' => 2,
            'asset_code' => 'EUR', 'analytic_axes' => json_encode([$axis], JSON_THROW_ON_ERROR), 'created_at' => $day.' 12:00:00+00',
        ]);
    }

    private static function assertDecimal(string $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertSame(0, DecimalValue::fromString($expected)->compareTo(DecimalValue::fromString($actual)), $actual.' is not '.$expected);
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

    /** @return array<string, mixed> */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);
        $fields = [];
        foreach ($value as $key => $item) {
            $fields[(string) $key] = $item;
        }

        return $fields;
    }

    private function storedPolicies(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM budget_emergency_fund_policies');
        self::assertIsInt($count);

        return $count;
    }
}
