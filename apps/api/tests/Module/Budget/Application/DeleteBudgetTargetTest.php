<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Budget\Application\BudgetAuditEvents;
use App\Module\Budget\Application\BudgetPlanConflict;
use App\Module\Budget\Application\BudgetTargetNotFound;
use App\Module\Budget\Application\DeleteBudgetTarget;
use App\Module\Budget\Application\InvalidBudgetTargetInput;
use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Budget\Domain\BudgetPeriodType;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Budget\Domain\BudgetScopeType;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetValueType;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Budget\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Budget\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Budget\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Budget\Application\Double\InMemoryBudgetPlanRepository;
use App\Tests\Module\Budget\Application\Double\InMemoryBudgetTargetRepository;
use App\Tests\Module\Budget\Application\Double\SequenceUuidGenerator;
use PHPUnit\Framework\TestCase;

final class DeleteBudgetTargetTest extends TestCase
{
    private const string WORKSPACE = '11111111-1111-4111-8111-111111111111';
    private const string OTHER_WORKSPACE = '22222222-2222-4222-8222-222222222222';
    private const string PLAN_ID = '33333333-3333-4333-8333-333333333333';
    private const string TARGET_ID = '44444444-4444-4444-8444-444444444444';

    public function testItDeletesATargetFromADraftPlanAndAuditsOnlyItsExistence(): void
    {
        [$delete, $targets, $audit] = $this->wired(BudgetPlanState::DRAFT);

        ($delete)(self::TARGET_ID, 1);

        self::assertNull($targets->find(WorkspaceScope::fromString(self::WORKSPACE), self::TARGET_ID));
        $lastKey = array_key_last($audit->events);
        self::assertNotNull($lastKey);
        $event = $audit->events[$lastKey];
        self::assertSame(BudgetAuditEvents::TARGET_REMOVED, $event->eventType);
        self::assertSame(['exists' => 'true'], $event->diff->before);
        self::assertSame(['exists' => 'false'], $event->diff->after);
        self::assertStringNotContainsString('300.00', json_encode($event->diff, JSON_THROW_ON_ERROR));
    }

    public function testItDeletesATargetFromAnActivePlan(): void
    {
        [$delete, $targets] = $this->wired(BudgetPlanState::ACTIVE);

        ($delete)(self::TARGET_ID, 1);

        self::assertNull($targets->find(WorkspaceScope::fromString(self::WORKSPACE), self::TARGET_ID));
    }

    public function testAClosedPlanTargetCannotBeDeleted(): void
    {
        [$delete] = $this->wired(BudgetPlanState::CLOSED);

        $this->expectException(InvalidBudgetTargetInput::class);

        ($delete)(self::TARGET_ID, 1);
    }

    public function testAStaleVersionIsAConflict(): void
    {
        [$delete] = $this->wired(BudgetPlanState::DRAFT);

        $this->expectException(BudgetPlanConflict::class);

        ($delete)(self::TARGET_ID, 2);
    }

    public function testAConditionalDeleteLostToAConcurrentWriterIsAConflict(): void
    {
        [$delete, $targets] = $this->wired(BudgetPlanState::DRAFT);
        $targets->failNextRemove();

        $this->expectException(BudgetPlanConflict::class);

        ($delete)(self::TARGET_ID, 1);
    }

    public function testATargetFromAnotherWorkspaceIsNotFound(): void
    {
        [, $targets] = $this->wired(BudgetPlanState::DRAFT);
        $delete = new DeleteBudgetTarget(
            new FixedCallerWorkspace(self::OTHER_WORKSPACE),
            new InMemoryBudgetPlanRepository(),
            $targets,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent(new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
        );

        $this->expectException(BudgetTargetNotFound::class);

        ($delete)(self::TARGET_ID, 1);
    }

    /** @return array{DeleteBudgetTarget, InMemoryBudgetTargetRepository, CollectingAuditEventRepository} */
    private function wired(BudgetPlanState $state): array
    {
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $plans = new InMemoryBudgetPlanRepository();
        $plans->add(new BudgetPlan(
            id: self::PLAN_ID,
            workspace: $workspace,
            period: BudgetPeriod::fromKey(BudgetPeriodType::MONTH, '2026-09'),
            assetCode: AssetCode::fromString('EUR'),
            state: $state,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        ));
        $targets = new InMemoryBudgetTargetRepository();
        $targets->add(new BudgetTarget(
            id: self::TARGET_ID,
            workspace: $workspace,
            planId: self::PLAN_ID,
            scopeType: BudgetScopeType::AXIS,
            scopeId: 'ESSENTIAL',
            valueType: BudgetValueType::AMOUNT,
            amount: DecimalValue::fromString('300.00'),
            ratio: null,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        ));
        $audit = new CollectingAuditEventRepository();

        return [
            new DeleteBudgetTarget(
                new FixedCallerWorkspace(self::WORKSPACE),
                $plans,
                $targets,
                new ImmediateTransactionBoundary(),
                new RecordAuditEvent($audit, new SequenceUuidGenerator()),
            ),
            $targets,
            $audit,
        ];
    }
}
