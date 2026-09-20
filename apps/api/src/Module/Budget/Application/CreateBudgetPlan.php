<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Budget\Domain\BudgetPeriodType;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Budget\Domain\InvalidBudgetPeriod;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\AssetKind;

final readonly class CreateBudgetPlan
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private BudgetPlanRepository $plans,
        private AssetCatalog $assets,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(CreateBudgetPlanInput $input): BudgetPlanView
    {
        $context = $this->caller->resolveContext();

        $periodType = BudgetPeriodType::tryFrom($input->periodType);
        if (null === $periodType) {
            throw new InvalidBudgetPlanInput('A budget plan period type must be MONTH or YEAR.');
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

        return $this->transactionBoundary->transactional(function () use ($context, $period, $assetCode): BudgetPlanView {
            $now = new \DateTimeImmutable();
            $plan = new BudgetPlan(
                id: $this->uuidGenerator->generate(),
                workspace: $context->workspace,
                period: $period,
                assetCode: $assetCode,
                state: BudgetPlanState::DRAFT,
                version: 1,
                createdAt: $now,
                updatedAt: $now,
            );

            $this->plans->add($plan);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: BudgetAuditEvents::PLAN_CREATED,
                entityType: BudgetAuditEvents::PLAN_ENTITY,
                entityId: $plan->id,
                diff: AuditDiff::creation([
                    'periodType' => $plan->period->type->value,
                    'period' => $plan->period->key(),
                    'assetCode' => $plan->assetCode->toString(),
                ]),
            ));

            return (new PresentBudgetPlan())($plan);
        });
    }
}
