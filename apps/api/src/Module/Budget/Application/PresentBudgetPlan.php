<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Budget\Domain\BudgetPlan;

final class PresentBudgetPlan
{
    public function __invoke(BudgetPlan $plan): BudgetPlanView
    {
        return new BudgetPlanView(
            id: $plan->id,
            periodType: $plan->period->type->value,
            period: $plan->period->key(),
            assetCode: $plan->assetCode->toString(),
            state: $plan->state->value,
            version: $plan->version,
        );
    }
}
