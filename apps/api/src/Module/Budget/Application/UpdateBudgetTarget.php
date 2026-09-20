<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetTargetRepository;
use App\Module\Budget\Domain\BudgetValueType;
use App\Module\Budget\Domain\InvalidBudgetTarget;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Reference\Application\AssetCatalog;

final readonly class UpdateBudgetTarget
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private BudgetPlanRepository $plans,
        private BudgetTargetRepository $targets,
        private AssetCatalog $assets,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(UpdateBudgetTargetInput $input): BudgetTargetView
    {
        $context = $this->caller->resolveContext();

        $valueType = BudgetValueType::tryFrom($input->valueType);
        if (null === $valueType) {
            throw new InvalidBudgetTargetInput('A budget target value type must be recognised.');
        }

        try {
            $amount = null === $input->amount ? null : DecimalValue::fromString($input->amount);
            $ratio = null === $input->ratio ? null : DecimalValue::fromString($input->ratio);
        } catch (MalformedDecimal|PrecisionExceeded $exception) {
            throw new InvalidBudgetTargetInput($exception->getMessage(), previous: $exception);
        }

        return $this->transactionBoundary->transactional(function () use ($context, $input, $valueType, $amount, $ratio): BudgetTargetView {
            $target = $this->targets->findForUpdate($context->workspace, $input->targetId);
            if (null === $target) {
                throw new BudgetTargetNotFound('This budget target does not exist in this workspace.');
            }
            if ($target->version !== $input->expectedVersion) {
                throw new BudgetPlanConflict('This budget target was changed by another edit; reload and try again.');
            }
            $plan = $this->plans->findForUpdate($context->workspace, $target->planId);
            if (null === $plan) {
                throw new BudgetPlanNotFound('This budget plan does not exist in this workspace.');
            }
            if (BudgetPlanState::CLOSED === $plan->state) {
                throw new InvalidBudgetTargetInput('A closed budget plan cannot be edited.');
            }
            if (null !== $amount) {
                $asset = $this->assets->findByCode($plan->assetCode);
                if (null === $asset) {
                    throw new InvalidBudgetTargetInput('The budget plan asset is no longer available.');
                }
                try {
                    $amount->assertScaleAtMost($asset->precision->storage);
                } catch (PrecisionExceeded $exception) {
                    throw new InvalidBudgetTargetInput($exception->getMessage(), previous: $exception);
                }
            }

            try {
                $updated = new BudgetTarget(
                    id: $target->id,
                    workspace: $target->workspace,
                    planId: $target->planId,
                    scopeType: $target->scopeType,
                    scopeId: $target->scopeId,
                    valueType: $valueType,
                    amount: $amount,
                    ratio: $ratio,
                    version: $target->version + 1,
                    createdAt: $target->createdAt,
                    updatedAt: new \DateTimeImmutable(),
                );
            } catch (InvalidBudgetTarget $exception) {
                throw new InvalidBudgetTargetInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->targets->update($updated, $target->version)) {
                throw new BudgetPlanConflict('This budget target was changed by another edit; reload and try again.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: BudgetAuditEvents::TARGET_UPDATED,
                entityType: BudgetAuditEvents::TARGET_ENTITY,
                entityId: $updated->id,
                diff: AuditDiff::change(
                    ['configurationKind' => $target->valueType->value],
                    ['configurationKind' => $updated->valueType->value],
                ),
            ));

            return (new PresentBudgetTarget())($updated);
        });
    }
}
