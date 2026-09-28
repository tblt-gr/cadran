<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalMetric: string
{
    case NET_SAVINGS_TRANSFERS = 'NET_SAVINGS_TRANSFERS';
    case BUDGET_SURPLUS = 'BUDGET_SURPLUS';
    case CATEGORY_SPENDING = 'CATEGORY_SPENDING';
    case AXIS_SPENDING = 'AXIS_SPENDING';
    case ACCOUNT_CONTRIBUTIONS = 'ACCOUNT_CONTRIBUTIONS';

    public function direction(): GoalDirection
    {
        return match ($this) {
            self::CATEGORY_SPENDING, self::AXIS_SPENDING => GoalDirection::CEILING,
            default => GoalDirection::MINIMUM,
        };
    }

    public function accepts(GoalKind $kind, GoalScopeType $scope): bool
    {
        return match ($this) {
            self::NET_SAVINGS_TRANSFERS => GoalScopeType::WORKSPACE === $scope,
            self::BUDGET_SURPLUS => GoalKind::ANNUAL === $kind && GoalScopeType::WORKSPACE === $scope,
            self::CATEGORY_SPENDING => GoalScopeType::CATEGORY === $scope,
            self::AXIS_SPENDING => GoalScopeType::AXIS === $scope,
            self::ACCOUNT_CONTRIBUTIONS => GoalKind::ANNUAL === $kind && GoalScopeType::ACCOUNT === $scope,
        };
    }
}
