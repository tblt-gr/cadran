<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

enum GoalMetric: string
{
    case NET_WORTH = 'NET_WORTH';
    case ACCOUNT_BALANCE = 'ACCOUNT_BALANCE';
    case NET_SAVINGS_TRANSFERS = 'NET_SAVINGS_TRANSFERS';
    case BUDGET_SURPLUS = 'BUDGET_SURPLUS';
    case CATEGORY_SPENDING = 'CATEGORY_SPENDING';
    case AXIS_SPENDING = 'AXIS_SPENDING';
    case ACCOUNT_CONTRIBUTIONS = 'ACCOUNT_CONTRIBUTIONS';
    case SAVINGS_RATE = 'SAVINGS_RATE';
    case NET_SAVINGS_RATE = 'NET_SAVINGS_RATE';

    public function direction(): GoalDirection
    {
        return match ($this) {
            self::CATEGORY_SPENDING, self::AXIS_SPENDING => GoalDirection::CEILING,
            default => GoalDirection::MINIMUM,
        };
    }

    public function isStock(): bool
    {
        return self::NET_WORTH === $this || self::ACCOUNT_BALANCE === $this;
    }

    public function isRate(): bool
    {
        return self::SAVINGS_RATE === $this || self::NET_SAVINGS_RATE === $this;
    }

    /**
     * The whole metric matrix: every metric accepts both periodicities, the rate
     * metrics take a rate only, the others an amount or an income share, and
     * the rolling share is a monthly notion.
     */
    public function accepts(GoalPeriodicity $periodicity, GoalValueType $valueType, GoalScopeType $scope): bool
    {
        if ($this->isStock()) {
            return GoalValueType::AMOUNT === $valueType && $this->acceptsScope($scope);
        }
        $valueAccepted = match ($valueType) {
            GoalValueType::RATE => $this->isRate(),
            GoalValueType::ROLLING_INCOME_SHARE => !$this->isRate() && GoalPeriodicity::MONTHLY === $periodicity,
            GoalValueType::AMOUNT, GoalValueType::INCOME_SHARE => !$this->isRate(),
        };

        return $this->acceptsScope($scope) && $valueAccepted;
    }

    public function acceptsScope(GoalScopeType $scope): bool
    {
        return match ($this) {
            self::NET_WORTH, self::NET_SAVINGS_TRANSFERS, self::BUDGET_SURPLUS, self::SAVINGS_RATE, self::NET_SAVINGS_RATE => GoalScopeType::WORKSPACE === $scope,
            self::CATEGORY_SPENDING => GoalScopeType::CATEGORY === $scope,
            self::AXIS_SPENDING => GoalScopeType::AXIS === $scope,
            self::ACCOUNT_CONTRIBUTIONS, self::ACCOUNT_BALANCE => GoalScopeType::ACCOUNT === $scope,
        };
    }
}
