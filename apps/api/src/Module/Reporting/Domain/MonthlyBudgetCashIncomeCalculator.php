<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\ExactDecimal;

/** Derives the Budget response metric without applying the persistence width to its sum. */
final class MonthlyBudgetCashIncomeCalculator
{
    /**
     * @param list<string>          $accountAssets
     * @param list<MonthlyMovement> $movements
     */
    public static function compute(array $accountAssets, array $movements): MonthlyBudgetCashIncome
    {
        $assets = array_values(array_unique($accountAssets));
        if ([] === $assets) {
            return new MonthlyBudgetCashIncome(null, null, MonthlyProjectionReason::NO_ACCOUNT);
        }
        if (count($assets) > 1) {
            return new MonthlyBudgetCashIncome(null, null, MonthlyProjectionReason::MIXED_ASSETS);
        }

        $asset = AssetCode::fromString($assets[0]);
        $income = '0';
        foreach ($movements as $movement) {
            if (!$movement->asset->equals($asset)) {
                return new MonthlyBudgetCashIncome(null, null, MonthlyProjectionReason::MIXED_ASSETS);
            }
            if (MonthlyMovementKind::INCOME === $movement->kind) {
                $income = ExactDecimal::addForResponse($income, $movement->amount->toString());
            }
        }

        return new MonthlyBudgetCashIncome($income, $asset, null);
    }
}
