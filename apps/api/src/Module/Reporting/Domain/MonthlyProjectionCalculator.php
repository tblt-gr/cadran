<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/** The single built-in monthly KPI policy for this release. */
final class MonthlyProjectionCalculator
{
    /**
     * @param list<string>          $accountAssets
     * @param list<MonthlyMovement> $movements                booked, live source movements only
     * @param array<string, bool>   $budgetIncludedByCategory
     */
    public static function compute(array $accountAssets, array $movements, array $budgetIncludedByCategory): MonthlyTransactionMetrics
    {
        $assets = array_values(array_unique($accountAssets));
        if (count($assets) > 1) {
            return self::allMissing(MonthlyProjectionReason::MIXED_ASSETS);
        }
        if ([] === $assets) {
            return self::allMissing(MonthlyProjectionReason::NO_ACCOUNT);
        }

        $asset = AssetCode::fromString($assets[0]);
        $income = DecimalValue::zero();
        $expenseSigned = DecimalValue::zero();
        $uncategorizedSigned = DecimalValue::zero();
        $savings = DecimalValue::zero();

        foreach ($movements as $movement) {
            if (!$movement->asset->equals($asset)) {
                return self::allMissing(MonthlyProjectionReason::MIXED_ASSETS);
            }

            if (MonthlyMovementKind::INCOME === $movement->kind) {
                $income = ExactDecimal::add($income, $movement->amount);
                continue;
            }

            if (MonthlyMovementKind::TRANSFER === $movement->kind) {
                if ($movement->savingsDestination && !$movement->amount->isNegative()) {
                    $savings = ExactDecimal::add($savings, $movement->amount);
                }
                continue;
            }

            if (!in_array($movement->kind, [MonthlyMovementKind::EXPENSE, MonthlyMovementKind::FEE, MonthlyMovementKind::REFUND], true)) {
                continue;
            }

            if ([] === $movement->splits) {
                $expenseSigned = ExactDecimal::add($expenseSigned, $movement->amount);
                $uncategorizedSigned = ExactDecimal::add($uncategorizedSigned, $movement->amount);
                continue;
            }

            foreach ($movement->splits as $split) {
                if ($budgetIncludedByCategory[$split->categoryId] ?? false) {
                    $expenseSigned = ExactDecimal::add($expenseSigned, $split->amount);
                }
            }
        }

        $expenses = ExactDecimal::negate($expenseSigned);
        $uncategorized = ExactDecimal::negate($uncategorizedSigned);
        $surplus = ExactDecimal::subtract($income, $expenses);
        $rate = ExactDecimal::isZero($income)
            ? MonthlyMetric::missing(MonthlyProjectionReason::ZERO_CASH_INCOME)
            : MonthlyMetric::value(ExactDecimal::divide($surplus, $income));

        return new MonthlyTransactionMetrics(
            MonthlyMetric::value($income, $asset),
            MonthlyMetric::value($expenses, $asset),
            MonthlyMetric::value($uncategorized, $asset),
            MonthlyMetric::value($surplus, $asset),
            MonthlyMetric::value($savings, $asset),
            $rate,
        );
    }

    private static function allMissing(MonthlyProjectionReason $reason): MonthlyTransactionMetrics
    {
        return new MonthlyTransactionMetrics(
            MonthlyMetric::missing($reason),
            MonthlyMetric::missing($reason),
            MonthlyMetric::missing($reason),
            MonthlyMetric::missing($reason),
            MonthlyMetric::missing($reason),
            MonthlyMetric::missing($reason),
        );
    }
}
