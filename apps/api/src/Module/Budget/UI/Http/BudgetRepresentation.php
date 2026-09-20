<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

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
}
