<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Budget\Domain\BudgetScopeType;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Categories\Application\CategoryReferenceFact;
use App\Module\Categories\Domain\AnalyticAxis;

final class BudgetScopeLabel
{
    public static function resolve(BudgetTarget $target, ?CategoryReferenceFact $category): string
    {
        if (BudgetScopeType::AXIS === $target->scopeType) {
            return match (AnalyticAxis::tryFrom($target->scopeId)) {
                AnalyticAxis::DISCRETIONARY => 'Discretionary',
                AnalyticAxis::ESSENTIAL => 'Essential',
                AnalyticAxis::FIXED => 'Fixed',
                AnalyticAxis::PERSONAL => 'Personal',
                AnalyticAxis::PROFESSIONAL => 'Professional',
                AnalyticAxis::VARIABLE => 'Variable',
                null => 'Unavailable axis',
            };
        }

        if (null !== $category) {
            return $category->label;
        }

        return BudgetScopeType::CATEGORY === $target->scopeType ? 'Unavailable category' : 'Unavailable group';
    }
}
