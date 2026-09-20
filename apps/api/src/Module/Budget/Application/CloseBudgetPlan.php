<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\InvalidBudgetPlan;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;

final readonly class CloseBudgetPlan
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private BudgetPlanRepository $plans,
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

            try {
                $closed = $plan->close(new \DateTimeImmutable());
            } catch (InvalidBudgetPlan $exception) {
                throw new BudgetPlanConflict($exception->getMessage(), previous: $exception);
            }

            if (!$this->plans->update($closed, $plan->version)) {
                throw new BudgetPlanConflict('This budget plan was changed by another edit; reload and try again.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: BudgetAuditEvents::PLAN_CLOSED,
                entityType: BudgetAuditEvents::PLAN_ENTITY,
                entityId: $closed->id,
                diff: AuditDiff::change(['state' => 'ACTIVE'], ['state' => 'CLOSED']),
            ));

            return (new PresentBudgetPlan())($closed);
        });
    }
}
