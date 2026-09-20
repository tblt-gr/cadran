<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Budget\Application\BudgetPlanNotFound;
use App\Module\Budget\Application\CreateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlanInput;
use App\Module\Budget\Application\CreateBudgetTarget;
use App\Module\Budget\Application\CreateBudgetTargetInput;
use App\Module\Budget\Application\ReadBudgetPlan;
use App\Module\Budget\Application\ReadPeriodCashIncome;
use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Budget\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Budget\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Budget\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Budget\Application\Double\InMemoryBudgetPlanRepository;
use App\Tests\Module\Budget\Application\Double\InMemoryBudgetTargetRepository;
use App\Tests\Module\Budget\Application\Double\InMemoryTransactionRepository;
use App\Tests\Module\Budget\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Categories\Application\Double\InMemoryCategoryRepository;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;

final class ReadBudgetPlanTest extends TestCase
{
    private const string WORKSPACE = AccountFixture::WORKSPACE;
    private const string OTHER_WORKSPACE = '22222222-2222-4222-8222-222222222222';
    private const string PARENT_CATEGORY_ID = '44444444-4444-4444-8444-444444444444';
    private const string CHILD_CATEGORY_ID = '55555555-5555-4555-8555-555555555555';

    public function testAnAmountTargetResolvesToItsOwnStoredValue(): void
    {
        [$read, $planId] = $this->wired(withIncome: false);

        $view = ($read)($planId);

        self::assertSame('300.00', $view->targets[0]->resolvedAmount);
        self::assertNull($view->targets[0]->nonCalculableReason);
    }

    public function testARatioTargetResolvesAgainstThePeriodCashIncome(): void
    {
        // Hand-computed: 30 % of a 2000.00 cash income is exactly 600.00.
        [$read, $planId] = $this->wired(withIncome: true, ratio: '0.30', income: '2000.00');

        $view = ($read)($planId);

        $ratioTarget = array_values(array_filter($view->targets, static fn ($t) => 'RATIO' === $t->valueType))[0];
        self::assertSame('600.0000', $ratioTarget->resolvedAmount);
    }

    public function testARatioTargetIsNonCalculableWhenThereIsNoIncome(): void
    {
        [$read, $planId] = $this->wired(withIncome: true, ratio: '0.30', income: null);

        $view = ($read)($planId);

        $ratioTarget = array_values(array_filter($view->targets, static fn ($t) => 'RATIO' === $t->valueType))[0];
        self::assertNull($ratioTarget->resolvedAmount);
        self::assertSame('ZERO_CASH_INCOME', $ratioTarget->nonCalculableReason);
    }

    public function testARatioTargetRejectsIncomeInAnAssetDifferentFromThePlan(): void
    {
        [$read, $planId] = $this->wired(withIncome: true, ratio: '0.30', income: '2000.00', incomeAsset: 'USD');

        $view = ($read)($planId);

        $ratioTarget = array_values(array_filter($view->targets, static fn ($target) => 'RATIO' === $target->valueType))[0];
        self::assertNull($ratioTarget->resolvedAmount);
        self::assertSame('MIXED_ASSETS', $ratioTarget->nonCalculableReason);
    }

    public function testARatioProductAtMaximumOperandScalesIsRoundedToTheResponseScale(): void
    {
        // Hand-computed: multiplying by 1 leaves the 24-decimal ratio unchanged;
        // the raw product has scale 48 and the response boundary caps it at 24.
        [$read, $planId] = $this->wired(
            withIncome: true,
            ratio: '0.123456789012345678901234',
            income: '1.000000000000000000000000',
        );

        $view = ($read)($planId);

        self::assertSame('0.123456789012345678901234', $view->targets[0]->resolvedAmount);
    }

    public function testOverlappingParentAndChildTargetsAreBothFlaggedWithoutAlteringTheirOwnAmounts(): void
    {
        [$read, $planId] = $this->wiredWithOverlap();

        $view = ($read)($planId);

        self::assertTrue($view->targets[0]->overlapping);
        self::assertTrue($view->targets[1]->overlapping);
        self::assertSame('300.00', $view->targets[0]->resolvedAmount);
        self::assertSame('100.00', $view->targets[1]->resolvedAmount);
    }

    public function testAPlanFromAnotherWorkspaceIsNotFound(): void
    {
        [, $planId, $plans, $targets, $categories] = $this->wired(withIncome: false, returnRepos: true);
        $foreignRead = new ReadBudgetPlan(
            new FixedCallerWorkspace(self::OTHER_WORKSPACE),
            $plans,
            $targets,
            new ReadCategoryReference($categories),
            new ReadPeriodCashIncome(new InMemoryAccountRepository(), new InMemoryTransactionRepository(), new FixedWorkspaceTimezoneReader()),
        );

        $this->expectException(BudgetPlanNotFound::class);

        ($foreignRead)($planId);
    }

    /** @return array{ReadBudgetPlan, string, InMemoryBudgetPlanRepository, InMemoryBudgetTargetRepository, InMemoryCategoryRepository} */
    private function wired(bool $withIncome, ?string $ratio = null, ?string $income = null, bool $returnRepos = false, string $incomeAsset = 'EUR'): array
    {
        $plans = new InMemoryBudgetPlanRepository();
        $targets = new InMemoryBudgetTargetRepository();
        $categories = new InMemoryCategoryRepository();
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $categories->add(new Category(
            id: self::PARENT_CATEGORY_ID,
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            type: CategoryType::EXPENSE,
            label: 'Groceries',
            parentId: null,
            icon: null,
            color: null,
            defaultAnalyticAxes: [],
            budgetIncluded: true,
            sortOrder: 0,
            depth: 1,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        ));
        $audit = new CollectingAuditEventRepository();
        $caller = new FixedCallerWorkspace(self::WORKSPACE);
        $assets = InMemoryAssetCatalog::withCodes('EUR', 'USD');

        $createPlan = new CreateBudgetPlan($caller, $plans, $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        $plan = ($createPlan)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        $createTarget = new CreateBudgetTarget($caller, $plans, $targets, new ReadCategoryReference($categories), $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));

        if (null !== $ratio) {
            ($createTarget)(new CreateBudgetTargetInput($plan->id, 'AXIS', 'ESSENTIAL', 'RATIO', null, $ratio));
        } else {
            ($createTarget)(new CreateBudgetTargetInput($plan->id, 'CATEGORY', self::PARENT_CATEGORY_ID, 'AMOUNT', '300.00', null));
        }

        $accounts = new InMemoryAccountRepository(AccountFixture::account(assetCode: $incomeAsset));
        $transactionsRepo = null === $income
            ? new InMemoryTransactionRepository()
            : new InMemoryTransactionRepository($this->income($income, $incomeAsset));
        $incomeReader = new ReadPeriodCashIncome($accounts, $transactionsRepo, new FixedWorkspaceTimezoneReader());

        $read = new ReadBudgetPlan($caller, $plans, $targets, new ReadCategoryReference($categories), $incomeReader);

        return [$read, $plan->id, $plans, $targets, $categories];
    }

    /** @return array{ReadBudgetPlan, string} */
    private function wiredWithOverlap(): array
    {
        $plans = new InMemoryBudgetPlanRepository();
        $targets = new InMemoryBudgetTargetRepository();
        $categories = new InMemoryCategoryRepository();
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $categories->add(new Category(
            id: self::PARENT_CATEGORY_ID,
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            type: CategoryType::EXPENSE,
            label: 'Groceries',
            parentId: null,
            icon: null,
            color: null,
            defaultAnalyticAxes: [],
            budgetIncluded: true,
            sortOrder: 0,
            depth: 1,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        ));
        $categories->add(new Category(
            id: self::CHILD_CATEGORY_ID,
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            type: CategoryType::EXPENSE,
            label: 'Supermarket',
            parentId: self::PARENT_CATEGORY_ID,
            icon: null,
            color: null,
            defaultAnalyticAxes: [],
            budgetIncluded: true,
            sortOrder: 0,
            depth: 2,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        ));
        $audit = new CollectingAuditEventRepository();
        $caller = new FixedCallerWorkspace(self::WORKSPACE);
        $assets = InMemoryAssetCatalog::withCodes('EUR');

        $createPlan = new CreateBudgetPlan($caller, $plans, $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        $plan = ($createPlan)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        $createTarget = new CreateBudgetTarget($caller, $plans, $targets, new ReadCategoryReference($categories), $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        ($createTarget)(new CreateBudgetTargetInput($plan->id, 'GROUP', self::PARENT_CATEGORY_ID, 'AMOUNT', '300.00', null));
        ($createTarget)(new CreateBudgetTargetInput($plan->id, 'CATEGORY', self::CHILD_CATEGORY_ID, 'AMOUNT', '100.00', null));

        $accounts = new InMemoryAccountRepository(AccountFixture::account());
        $incomeReader = new ReadPeriodCashIncome($accounts, new InMemoryTransactionRepository(), new FixedWorkspaceTimezoneReader());
        $read = new ReadBudgetPlan($caller, $plans, $targets, new ReadCategoryReference($categories), $incomeReader);

        return [$read, $plan->id];
    }

    private function income(string $amount, string $assetCode = 'EUR'): Transaction
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-000000000099',
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            accountId: AccountFixture::ID,
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString($assetCode)),
            originalAmount: null,
            exchangeRate: null,
            state: TransactionState::BOOKED,
            nature: TransactionNature::INCOME,
            source: TransactionSource::MANUAL,
            sourceRef: null,
            bookedOn: new \DateTimeImmutable('2026-09-05', new \DateTimeZone('UTC')),
            valueOn: null,
            authorizedOn: null,
            rawLabel: 'Salary',
            counterparty: null,
            note: null,
            paymentMethod: null,
            mcc: null,
            maskedCard: null,
            bankReference: null,
            splits: [],
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            voidedAt: null,
            lastEditorId: null,
        );
    }
}
