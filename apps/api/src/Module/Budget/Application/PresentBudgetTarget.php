<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Budget\Domain\BudgetTarget;

final class PresentBudgetTarget
{
    public function __invoke(BudgetTarget $target): BudgetTargetView
    {
        return new BudgetTargetView(
            id: $target->id,
            planId: $target->planId,
            scopeType: $target->scopeType->value,
            scopeId: $target->scopeId,
            valueType: $target->valueType->value,
            amount: $target->amount?->toString(),
            ratio: $target->ratio?->toString(),
            version: $target->version,
        );
    }
}
