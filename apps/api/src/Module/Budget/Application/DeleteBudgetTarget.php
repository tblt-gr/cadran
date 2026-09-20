<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Budget\Domain\BudgetTargetRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;

final readonly class DeleteBudgetTarget
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private BudgetPlanRepository $plans,
        private BudgetTargetRepository $targets,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(string $targetId, int $expectedVersion): void
    {
        if ($expectedVersion < 1) {
            throw new InvalidBudgetTargetInput('A budget target deletion requires a positive version.');
        }

        $context = $this->caller->resolveContext();
        $this->transactionBoundary->transactional(function () use ($context, $targetId, $expectedVersion): void {
            $target = $this->targets->findForUpdate($context->workspace, $targetId);
            if (null === $target) {
                throw new BudgetTargetNotFound('This budget target does not exist in this workspace.');
            }
            if ($target->version !== $expectedVersion) {
                throw new BudgetPlanConflict('This budget target was changed by another edit; reload and try again.');
            }

            $plan = $this->plans->findForUpdate($context->workspace, $target->planId);
            if (null === $plan) {
                throw new BudgetPlanNotFound('This budget plan does not exist in this workspace.');
            }
            if (BudgetPlanState::CLOSED === $plan->state) {
                throw new InvalidBudgetTargetInput('A closed budget plan target cannot be deleted.');
            }
            if (!$this->targets->remove($target, $expectedVersion)) {
                throw new BudgetPlanConflict('This budget target was changed by another edit; reload and try again.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: BudgetAuditEvents::TARGET_REMOVED,
                entityType: BudgetAuditEvents::TARGET_ENTITY,
                entityId: $target->id,
                diff: AuditDiff::change(['exists' => true], ['exists' => false]),
            ));
        });
    }
}
