<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\BudgetTargetRepository;
use App\Module\Budget\Domain\InvalidBudgetPlan;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;

final readonly class ActivateBudgetPlan
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private BudgetPlanRepository $plans,
        private BudgetTargetRepository $targets,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(string $planId): BudgetPlanView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($context, $planId): BudgetPlanView {
            $plan = $this->plans->findForUpdate($context->workspace, $planId);
            if (null === $plan) {
                throw new BudgetPlanNotFound('This budget plan does not exist in this workspace.');
            }

            if (0 === $this->targets->countByPlan($context->workspace, $plan->id)) {
                throw new EmptyBudgetPlan('A budget plan with no target cannot be activated.');
            }

            if (null !== $this->plans->findActiveByPeriod($context->workspace, $plan->period->type, $plan->period->firstDay())) {
                throw new BudgetPlanConflict('Another plan is already active for this workspace and period.');
            }

            try {
                $activated = $plan->activate(new \DateTimeImmutable());
            } catch (InvalidBudgetPlan $exception) {
                throw new BudgetPlanConflict($exception->getMessage(), previous: $exception);
            }

            if (!$this->plans->update($activated, $plan->version)) {
                throw new BudgetPlanConflict('This budget plan was changed by another edit; reload and try again.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: BudgetAuditEvents::PLAN_ACTIVATED,
                entityType: BudgetAuditEvents::PLAN_ENTITY,
                entityId: $activated->id,
                diff: AuditDiff::change(['state' => 'DRAFT'], ['state' => 'ACTIVE']),
            ));

            return (new PresentBudgetPlan())($activated);
        });
    }
}
