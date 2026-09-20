<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\BudgetComparisonsView;
use App\Module\Budget\Application\BudgetComparisonView;
use App\Module\Budget\Application\BudgetPlanDetailView;
use App\Module\Budget\Application\BudgetPlanPage;
use App\Module\Budget\Application\BudgetPlanView;
use App\Module\Budget\Application\BudgetTargetDetailView;
use App\Module\Budget\Application\BudgetTargetView;

final class BudgetRepresentation
{
    /** @return array<string, mixed> */
    public static function plan(BudgetPlanView $plan): array
    {
        return [
            'id' => $plan->id,
            'periodType' => $plan->periodType,
            'period' => $plan->period,
            'assetCode' => $plan->assetCode,
            'state' => $plan->state,
            'version' => $plan->version,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(BudgetPlanDetailView $plan): array
    {
        return [
            'id' => $plan->id,
            'periodType' => $plan->periodType,
            'period' => $plan->period,
            'assetCode' => $plan->assetCode,
            'state' => $plan->state,
            'version' => $plan->version,
            'targets' => array_map(self::targetDetail(...), $plan->targets),
        ];
    }

    /** @return array<string, mixed> */
    public static function page(BudgetPlanPage $page): array
    {
        return ['items' => array_map(self::plan(...), $page->items), 'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total];
    }

    /** @return array<string, mixed> */
    public static function target(BudgetTargetView $target): array
    {
        return [
            'id' => $target->id, 'planId' => $target->planId, 'scopeType' => $target->scopeType,
            'scopeId' => $target->scopeId, 'valueType' => $target->valueType, 'amount' => $target->amount,
            'ratio' => $target->ratio, 'version' => $target->version,
        ];
    }

    /** @return array<string, mixed> */
    public static function comparisons(BudgetComparisonsView $view): array
    {
        return [
            'planId' => $view->planId,
            'period' => $view->period,
            'assetCode' => $view->assetCode,
            'status' => $view->status,
            'reason' => $view->reason,
            'comparisons' => array_map(self::comparison(...), $view->comparisons),
        ];
    }

    /** @return array<string, mixed> */
    private static function targetDetail(BudgetTargetDetailView $target): array
    {
        return [
            'id' => $target->id, 'scopeType' => $target->scopeType, 'scopeId' => $target->scopeId,
            'valueType' => $target->valueType, 'storedAmount' => $target->storedAmount,
            'storedRatio' => $target->storedRatio, 'resolvedAmount' => $target->resolvedAmount,
            'nonCalculableReason' => $target->nonCalculableReason, 'overlapping' => $target->overlapping,
            'version' => $target->version,
        ];
    }

    /** @return array<string, mixed> */
    private static function comparison(BudgetComparisonView $comparison): array
    {
        return [
            'targetId' => $comparison->targetId,
            'scopeType' => $comparison->scopeType,
            'scopeId' => $comparison->scopeId,
            'actual' => $comparison->actual,
            'actualReason' => $comparison->actualReason,
            'target' => $comparison->target,
            'targetReason' => $comparison->targetReason,
            'variance' => $comparison->variance,
            'status' => $comparison->status,
            'includedTransactionIds' => $comparison->includedTransactionIds,
            'pendingCount' => $comparison->pendingCount,
            'overlapping' => $comparison->overlapping,
            'policy' => $comparison->policy,
        ];
    }
}
