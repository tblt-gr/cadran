<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Budget\Application\ActivateBudgetPlan;
use App\Module\Budget\Application\BudgetAuditEvents;
use App\Module\Budget\Application\BudgetPlanConflict;
use App\Module\Budget\Application\CloseBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlanInput;
use App\Module\Budget\Application\CreateBudgetTarget;
use App\Module\Budget\Application\CreateBudgetTargetInput;
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

final class CloseBudgetPlanTest extends TestCase
{
    private const string WORKSPACE = '11111111-1111-4111-8111-111111111111';
    private const string CATEGORY_ID = '44444444-4444-4444-8444-444444444444';

    public function testClosingAnActivePlanMovesItToClosedAndAudits(): void
    {
        $plans = new InMemoryBudgetPlanRepository();
        $targets = new InMemoryBudgetTargetRepository();
        $categories = new InMemoryCategoryRepository();
        $audit = new CollectingAuditEventRepository();
        $caller = new FixedCallerWorkspace(self::WORKSPACE);
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $assets = InMemoryAssetCatalog::withCodes('EUR');

        $create = new CreateBudgetPlan($caller, $plans, $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        $plan = ($create)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        $categories->add(new Category(
            id: self::CATEGORY_ID,
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
        $createTarget = new CreateBudgetTarget($caller, $plans, $targets, new ReadCategoryReference($categories), $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        ($createTarget)(new CreateBudgetTargetInput($plan->id, 'CATEGORY', self::CATEGORY_ID, 'AMOUNT', '300.00', null));

        $activate = new ActivateBudgetPlan($caller, $plans, $targets, new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        ($activate)($plan->id);

        $close = new CloseBudgetPlan($caller, $plans, new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        $closed = ($close)($plan->id);

        self::assertSame('CLOSED', $closed->state);
        self::assertSame(3, $closed->version);
        self::assertNotEmpty($audit->events);
        self::assertSame(BudgetAuditEvents::PLAN_CLOSED, $audit->events[count($audit->events) - 1]->eventType);
    }

    public function testClosingADraftPlanIsRefused(): void
    {
        $plans = new InMemoryBudgetPlanRepository();
        $audit = new CollectingAuditEventRepository();
        $caller = new FixedCallerWorkspace(self::WORKSPACE);
        $create = new CreateBudgetPlan($caller, $plans, InMemoryAssetCatalog::withCodes('EUR'), new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        $plan = ($create)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        $close = new CloseBudgetPlan($caller, $plans, new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));

        $this->expectException(BudgetPlanConflict::class);

        ($close)($plan->id);
    }
}
