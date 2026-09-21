<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final class MonthlyKpiReasonExplanation
{
    public static function for(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'MIXED_ASSETS' => 'The metric cannot be calculated because its contributing sources use multiple assets.',
            'NO_ACCOUNT' => 'The metric cannot be calculated because no account is open in the requested period.',
            'ZERO_CASH_INCOME' => 'The metric cannot be calculated because cash income is exactly zero.',
            'MISSING_BENEFIT_SOURCE' => 'The metric cannot be calculated because non-cash benefit sources are not modelled in this release.',
            'MISSING_VALUATION' => 'The metric cannot be calculated because at least one required account valuation is missing.',
            'NO_ELIGIBLE_ACCOUNT' => 'The metric cannot be calculated because no account is eligible for net worth on the requested date.',
            'MISSING_TARGET' => 'The metric cannot be calculated because no budget target exists for the requested scope.',
            default => throw new \LogicException('A non-calculable monthly KPI has no human explanation.'),
        };
    }
}
