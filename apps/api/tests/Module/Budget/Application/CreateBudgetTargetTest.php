<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Budget\Application\BudgetPlanNotFound;
use App\Module\Budget\Application\CreateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlanInput;
use App\Module\Budget\Application\CreateBudgetTarget;
use App\Module\Budget\Application\CreateBudgetTargetInput;
use App\Module\Budget\Application\InvalidBudgetTargetInput;
use App\Module\Budget\Application\InvalidBudgetTargetReference;
use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Budget\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Budget\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Budget\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Budget\Application\Double\InMemoryBudgetPlanRepository;
use App\Tests\Module\Budget\Application\Double\InMemoryBudgetTargetRepository;
use App\Tests\Module\Budget\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Categories\Application\Double\InMemoryCategoryRepository;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;

final class CreateBudgetTargetTest extends TestCase
{
    private const string WORKSPACE = '11111111-1111-4111-8111-111111111111';
    private const string OTHER_WORKSPACE = '22222222-2222-4222-8222-222222222222';
    private const string CATEGORY_ID = '44444444-4444-4444-8444-444444444444';
    private const string OTHER_WORKSPACE_CATEGORY_ID = '77777777-7777-4777-8777-777777777777';

    public function testAnAmountTargetOnAnActiveCategoryIsCreated(): void
    {
        [$create, , $planId] = $this->wired();

        $view = ($create)(new CreateBudgetTargetInput($planId, 'CATEGORY', self::CATEGORY_ID, 'AMOUNT', '300.00', null));

        self::assertSame('300.00', $view->amount);
        self::assertSame('AMOUNT', $view->valueType);
    }

    public function testARatioTargetOnAnAnalyticAxisIsCreated(): void
    {
        [$create, , $planId] = $this->wired();

        $view = ($create)(new CreateBudgetTargetInput($planId, 'AXIS', 'ESSENTIAL', 'RATIO', null, '0.30'));

        self::assertSame('0.30', $view->ratio);
        self::assertSame('AXIS', $view->scopeType);
    }

    public function testAnArchivedCategoryReferenceIsRefused(): void
    {
        [$create, $categories, $planId] = $this->wired();
        $categories->add($this->category(self::CATEGORY_ID, archived: true));

        $this->expectException(InvalidBudgetTargetReference::class);

        ($create)(new CreateBudgetTargetInput($planId, 'CATEGORY', self::CATEGORY_ID, 'AMOUNT', '300.00', null));
    }

    public function testACategoryFromAnotherWorkspaceIsRefused(): void
    {
        [$create, $categories, $planId] = $this->wired();
        $categories->add($this->category(self::OTHER_WORKSPACE_CATEGORY_ID, workspace: self::OTHER_WORKSPACE));

        $this->expectException(InvalidBudgetTargetReference::class);

        ($create)(new CreateBudgetTargetInput($planId, 'CATEGORY', self::OTHER_WORKSPACE_CATEGORY_ID, 'AMOUNT', '300.00', null));
    }

    public function testAnUnknownAnalyticAxisIsRefused(): void
    {
        [$create, , $planId] = $this->wired();

        $this->expectException(InvalidBudgetTargetReference::class);

        ($create)(new CreateBudgetTargetInput($planId, 'AXIS', 'NOT_AN_AXIS', 'RATIO', null, '0.30'));
    }

    public function testATargetOnAPlanFromAnotherWorkspaceIsNotFound(): void
    {
        [, $categories, $planId] = $this->wired();
        $categories->add($this->category(self::CATEGORY_ID));
        $foreignCreate = new CreateBudgetTarget(
            new FixedCallerWorkspace(self::OTHER_WORKSPACE),
            new InMemoryBudgetPlanRepository(),
            new InMemoryBudgetTargetRepository(),
            new ReadCategoryReference($categories),
            InMemoryAssetCatalog::withCodes('EUR'),
            new SequenceUuidGenerator(),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent(new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
        );

        $this->expectException(BudgetPlanNotFound::class);

        ($foreignCreate)(new CreateBudgetTargetInput($planId, 'CATEGORY', self::CATEGORY_ID, 'AMOUNT', '300.00', null));
    }

    public function testAZeroAmountInputIsRefused(): void
    {
        [$create, , $planId] = $this->wired();

        $this->expectException(InvalidBudgetTargetInput::class);

        ($create)(new CreateBudgetTargetInput($planId, 'CATEGORY', self::CATEGORY_ID, 'AMOUNT', '0', null));
    }

    public function testAnAmountBeyondThePlanAssetPrecisionIsRefused(): void
    {
        [$create, , $planId] = $this->wired();

        $this->expectException(InvalidBudgetTargetInput::class);

        ($create)(new CreateBudgetTargetInput($planId, 'CATEGORY', self::CATEGORY_ID, 'AMOUNT', '1.123456789', null));
    }

    public function testAPlanCannotExceedTheTransactionalTargetCap(): void
    {
        [$create, , $planId, $targets] = $this->wired();
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        for ($index = 0; $index < \App\Module\Budget\Domain\BudgetOverlapDetector::MAX_TARGETS; ++$index) {
            $targets->add(new \App\Module\Budget\Domain\BudgetTarget(
                id: sprintf('00000000-0000-4000-8000-%012d', $index),
                workspace: WorkspaceScope::fromString(self::WORKSPACE),
                planId: $planId,
                scopeType: \App\Module\Budget\Domain\BudgetScopeType::AXIS,
                scopeId: 'AXIS_'.$index,
                valueType: \App\Module\Budget\Domain\BudgetValueType::RATIO,
                amount: null,
                ratio: \App\Module\Foundation\Domain\DecimalValue::fromString('0.01'),
                version: 1,
                createdAt: $now,
                updatedAt: $now,
            ));
        }

        $this->expectException(InvalidBudgetTargetInput::class);

        ($create)(new CreateBudgetTargetInput($planId, 'CATEGORY', self::CATEGORY_ID, 'AMOUNT', '1.00', null));
    }

    /** @return array{CreateBudgetTarget, InMemoryCategoryRepository, string, InMemoryBudgetTargetRepository} */
    private function wired(): array
    {
        $plans = new InMemoryBudgetPlanRepository();
        $targets = new InMemoryBudgetTargetRepository();
        $categories = new InMemoryCategoryRepository();
        $categories->add($this->category(self::CATEGORY_ID));
        $audit = new CollectingAuditEventRepository();
        $caller = new FixedCallerWorkspace(self::WORKSPACE);

        $assets = InMemoryAssetCatalog::withCodes('EUR');
        $createPlan = new CreateBudgetPlan($caller, $plans, $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        $plan = ($createPlan)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        $create = new CreateBudgetTarget($caller, $plans, $targets, new ReadCategoryReference($categories), $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));

        return [$create, $categories, $plan->id, $targets];
    }

    private function category(string $id, bool $archived = false, string $workspace = self::WORKSPACE): Category
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new Category(
            id: $id,
            workspace: WorkspaceScope::fromString($workspace),
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
            archivedAt: $archived ? $now : null,
        );
    }
}
