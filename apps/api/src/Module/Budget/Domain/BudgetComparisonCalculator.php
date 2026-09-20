<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

use App\Module\Foundation\Domain\ExactDecimal;

final class BudgetComparisonCalculator
{
    public static function compare(?string $actual, ?string $target, ?BudgetComparisonReason $reason): BudgetComparisonResult
    {
        if (null === $actual || null === $target) {
            if (null === $reason) {
                throw new \LogicException('A non-calculable budget comparison requires a reason.');
            }

            return new BudgetComparisonResult($actual, $target, null, 'NON_CALCULABLE', $reason);
        }

        $variance = ExactDecimal::subtractForResponse($target, $actual);
        $comparison = ExactDecimal::compareForResponse($actual, $target);

        return new BudgetComparisonResult(
            $actual,
            $target,
            $variance,
            match ($comparison) {
                -1 => 'WITHIN_TARGET',
                0 => 'ON_TARGET',
                default => 'OVER_TARGET',
            },
            null,
        );
    }
}
