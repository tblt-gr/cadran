<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Domain\MonthlyMetric;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlyProjectionReason;

/**
 * The "period cash income" a ratio target resolves against: the same
 * definition RPT-001 uses as the denominator of its savings rate. Reusing
 * {@see MonthlyMetric} and {@see MonthlyProjectionReason} keeps a null/zero
 * income non-calculable here exactly as it is there, never a silent zero.
 */
final class BudgetIncomeCalculator
{
    /**
     * @param list<string>                                       $accountAssets asset code of every workspace account open during the period
     * @param list<\App\Module\Reporting\Domain\MonthlyMovement> $movements     booked, live source movements of the period
     */
    public static function sumIncome(array $accountAssets, array $movements): MonthlyMetric
    {
        $assets = array_values(array_unique($accountAssets));
        if (count($assets) > 1) {
            return MonthlyMetric::missing(MonthlyProjectionReason::MIXED_ASSETS);
        }
        if ([] === $assets) {
            return MonthlyMetric::missing(MonthlyProjectionReason::NO_ACCOUNT);
        }

        $asset = AssetCode::fromString($assets[0]);
        $income = DecimalValue::zero();
        foreach ($movements as $movement) {
            if (!$movement->asset->equals($asset)) {
                return MonthlyMetric::missing(MonthlyProjectionReason::MIXED_ASSETS);
            }
            if (MonthlyMovementKind::INCOME === $movement->kind) {
                $income = ExactDecimal::add($income, $movement->amount);
            }
        }

        if (ExactDecimal::isZero($income)) {
            return MonthlyMetric::missing(MonthlyProjectionReason::ZERO_CASH_INCOME);
        }

        return MonthlyMetric::value($income, $asset);
    }
}
