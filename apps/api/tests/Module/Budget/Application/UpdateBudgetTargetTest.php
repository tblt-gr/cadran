<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Application\BudgetPlanConflict;
use App\Module\Budget\Application\BudgetTargetNotFound;
use App\Module\Budget\Application\CreateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlanInput;
use App\Module\Budget\Application\CreateBudgetTarget;
use App\Module\Budget\Application\CreateBudgetTargetInput;
use App\Module\Budget\Application\InvalidBudgetTargetInput;
use App\Module\Budget\Application\UpdateBudgetTarget;
use App\Module\Budget\Application\UpdateBudgetTargetInput;
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

final class UpdateBudgetTargetTest extends TestCase
{
    private const string WORKSPACE = '11111111-1111-4111-8111-111111111111';
    private const string OTHER_WORKSPACE = '22222222-2222-4222-8222-222222222222';
    private const string CATEGORY_ID = '44444444-4444-4444-8444-444444444444';

    public function testItReplacesTheAmountAndBumpsVersion(): void
    {
        [$update, , $targetId] = $this->wired();

        $view = ($update)(new UpdateBudgetTargetInput($targetId, 1, 'AMOUNT', '450.00', null));

        self::assertSame('450.00', $view->amount);
        self::assertSame(2, $view->version);
    }

    public function testItSwitchesFromAmountToRatio(): void
    {
        [$update, , $targetId, , $audit] = $this->wired();

        $view = ($update)(new UpdateBudgetTargetInput($targetId, 1, 'RATIO', null, '0.15'));

        self::assertSame('RATIO', $view->valueType);
        self::assertSame('0.15', $view->ratio);
        self::assertNull($view->amount);
        $lastKey = array_key_last($audit->events);
        self::assertNotNull($lastKey);
        $event = $audit->events[$lastKey];
        self::assertSame(['configurationKind' => 'AMOUNT'], $event->diff->before);
        self::assertSame(['configurationKind' => 'RATIO'], $event->diff->after);
        self::assertStringNotContainsString('0.15', json_encode($event->diff, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(AuditDiff::REDACTED, json_encode($event->diff, JSON_THROW_ON_ERROR));
    }

    public function testAStaleVersionIsRefused(): void
    {
        [$update, , $targetId] = $this->wired();

        $this->expectException(BudgetPlanConflict::class);

        ($update)(new UpdateBudgetTargetInput($targetId, 99, 'AMOUNT', '450.00', null));
    }

    public function testAZeroAmountIsRefused(): void
    {
        [$update, , $targetId] = $this->wired();

        $this->expectException(InvalidBudgetTargetInput::class);

        ($update)(new UpdateBudgetTargetInput($targetId, 1, 'AMOUNT', '0', null));
    }

    public function testAnAmountBeyondThePlanAssetPrecisionIsRefused(): void
    {
        [$update, , $targetId] = $this->wired();

        $this->expectException(InvalidBudgetTargetInput::class);

        ($update)(new UpdateBudgetTargetInput($targetId, 1, 'AMOUNT', '1.123456789', null));
    }

    public function testATargetFromAnotherWorkspaceIsNotFound(): void
    {
        [, $targets, $targetId, $plans] = $this->wired();
        $foreignUpdate = new UpdateBudgetTarget(
            new FixedCallerWorkspace(self::OTHER_WORKSPACE),
            $plans,
            $targets,
            InMemoryAssetCatalog::withCodes('EUR'),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent(new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
        );

        $this->expectException(BudgetTargetNotFound::class);

        ($foreignUpdate)(new UpdateBudgetTargetInput($targetId, 1, 'AMOUNT', '450.00', null));
    }

    /** @return array{UpdateBudgetTarget, InMemoryBudgetTargetRepository, string, InMemoryBudgetPlanRepository, CollectingAuditEventRepository} */
    private function wired(): array
    {
        $plans = new InMemoryBudgetPlanRepository();
        $targets = new InMemoryBudgetTargetRepository();
        $categories = new InMemoryCategoryRepository();
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
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
        $audit = new CollectingAuditEventRepository();
        $caller = new FixedCallerWorkspace(self::WORKSPACE);

        $assets = InMemoryAssetCatalog::withCodes('EUR');
        $createPlan = new CreateBudgetPlan($caller, $plans, $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        $plan = ($createPlan)(new CreateBudgetPlanInput('MONTH', '2026-09', 'EUR'));

        $createTarget = new CreateBudgetTarget($caller, $plans, $targets, new ReadCategoryReference($categories), $assets, new SequenceUuidGenerator(), new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));
        $target = ($createTarget)(new CreateBudgetTargetInput($plan->id, 'CATEGORY', self::CATEGORY_ID, 'AMOUNT', '300.00', null));

        $update = new UpdateBudgetTarget($caller, $plans, $targets, $assets, new ImmediateTransactionBoundary(), new RecordAuditEvent($audit, new SequenceUuidGenerator()));

        return [$update, $targets, $target->id, $plans, $audit];
    }
}
