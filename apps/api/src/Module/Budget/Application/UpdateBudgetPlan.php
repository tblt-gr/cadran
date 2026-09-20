<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Budget\Domain\BudgetPeriodType;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\InvalidBudgetPeriod;
use App\Module\Budget\Domain\InvalidBudgetPlan;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\AssetKind;

final readonly class UpdateBudgetPlan
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private BudgetPlanRepository $plans,
        private AssetCatalog $assets,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(UpdateBudgetPlanInput $input): BudgetPlanView
    {
        $context = $this->caller->resolveContext();
        $periodType = BudgetPeriodType::tryFrom($input->periodType);
        if (null === $periodType || $input->expectedVersion < 1) {
            throw new InvalidBudgetPlanInput('A budget plan edit requires a valid type and positive version.');
        }

        try {
            $period = BudgetPeriod::fromKey($periodType, $input->period);
            $assetCode = AssetCode::fromString($input->assetCode);
        } catch (InvalidBudgetPeriod|\InvalidArgumentException $exception) {
            throw new InvalidBudgetPlanInput($exception->getMessage(), previous: $exception);
        }

        $asset = $this->assets->findByCode($assetCode);
        if (null === $asset || AssetKind::FIAT !== $asset->kind) {
            throw new InvalidBudgetPlanInput('A budget plan asset must be a known fiat currency.');
        }

        return $this->transactionBoundary->transactional(function () use ($context, $input, $period, $assetCode): BudgetPlanView {
            $plan = $this->plans->findForUpdate($context->workspace, $input->planId);
            if (null === $plan) {
                throw new BudgetPlanNotFound('This budget plan does not exist in this workspace.');
            }
            if ($plan->version !== $input->expectedVersion) {
                throw new BudgetPlanConflict('This budget plan was changed by another edit; reload and try again.');
            }

            try {
                $updated = $plan->reconfigure($period, $assetCode, new \DateTimeImmutable());
            } catch (InvalidBudgetPlan $exception) {
                throw new BudgetPlanConflict($exception->getMessage(), previous: $exception);
            }
            if (!$this->plans->update($updated, $plan->version)) {
                throw new BudgetPlanConflict('This budget plan was changed by another edit; reload and try again.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: BudgetAuditEvents::PLAN_UPDATED,
                entityType: BudgetAuditEvents::PLAN_ENTITY,
                entityId: $updated->id,
                diff: AuditDiff::change(
                    ['periodType' => $plan->period->type->value, 'period' => $plan->period->key(), 'assetCode' => $plan->assetCode->toString()],
                    ['periodType' => $updated->period->type->value, 'period' => $updated->period->key(), 'assetCode' => $updated->assetCode->toString()],
                ),
            ));

            return (new PresentBudgetPlan())($updated);
        });
    }
}
