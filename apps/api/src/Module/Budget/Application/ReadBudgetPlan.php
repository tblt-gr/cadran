<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Budget\Domain\BudgetOverlapDetector;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetTargetRepository;
use App\Module\Budget\Domain\BudgetValueType;
use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Domain\MonthlyMetric;
use App\Module\Reporting\Domain\MonthlyProjectionReason;

/**
 * Assembles a plan with its targets resolved: an amount target reads its own
 * stored figure, a ratio target reads it against the period's cash income
 * (read once per plan, not once per target), and every target carries the
 * overlap flag {@see BudgetOverlapDetector} computes from the stored rows —
 * never a cached or double-counted total.
 */
final readonly class ReadBudgetPlan
{
    public function __construct(
        private CallerWorkspace $caller,
        private BudgetPlanRepository $plans,
        private BudgetTargetRepository $targets,
        private ReadCategoryReference $categoryReference,
        private ReadPeriodCashIncome $income,
    ) {
    }

    public function __invoke(string $planId): BudgetPlanDetailView
    {
        $workspace = $this->caller->resolve();

        $plan = $this->plans->find($workspace, $planId);
        if (null === $plan) {
            throw new BudgetPlanNotFound('This budget plan does not exist in this workspace.');
        }

        $targets = $this->targets->listByPlan($workspace, $plan->id, BudgetOverlapDetector::MAX_TARGETS + 1);
        $overlaps = BudgetOverlapDetector::detect(
            $targets,
            fn (string $categoryId): array => $this->categoryReference->ancestorIdsOf($workspace, $categoryId),
        );

        $cashIncome = null;
        $views = [];
        foreach ($targets as $target) {
            if (BudgetValueType::RATIO === $target->valueType) {
                $cashIncome ??= ($this->income)($workspace, $plan->period);
            }
            $views[] = $this->resolve($target, $plan, $cashIncome, $overlaps[$target->id] ?? false);
        }

        return new BudgetPlanDetailView(
            id: $plan->id,
            periodType: $plan->period->type->value,
            period: $plan->period->key(),
            assetCode: $plan->assetCode->toString(),
            state: $plan->state->value,
            version: $plan->version,
            targets: $views,
        );
    }

    private function resolve(BudgetTarget $target, BudgetPlan $plan, ?MonthlyMetric $cashIncome, bool $overlapping): BudgetTargetDetailView
    {
        $resolvedAmount = null;
        $nonCalculableReason = null;

        if (BudgetValueType::AMOUNT === $target->valueType) {
            $resolvedAmount = $target->amount?->toString();
        } elseif (null !== $cashIncome) {
            if (null === $cashIncome->value) {
                $nonCalculableReason = $cashIncome->reason?->value;
            } elseif (null === $cashIncome->asset || !$cashIncome->asset->equals($plan->assetCode)) {
                $nonCalculableReason = MonthlyProjectionReason::MIXED_ASSETS->value;
            } else {
                $resolvedAmount = ExactDecimal::multiplyForResponse($cashIncome->value, $target->ratio ?? \App\Module\Foundation\Domain\DecimalValue::zero());
            }
        }

        return new BudgetTargetDetailView(
            id: $target->id,
            scopeType: $target->scopeType->value,
            scopeId: $target->scopeId,
            valueType: $target->valueType->value,
            storedAmount: $target->amount?->toString(),
            storedRatio: $target->ratio?->toString(),
            resolvedAmount: $resolvedAmount,
            nonCalculableReason: $nonCalculableReason,
            overlapping: $overlapping,
            version: $target->version,
        );
    }
}
