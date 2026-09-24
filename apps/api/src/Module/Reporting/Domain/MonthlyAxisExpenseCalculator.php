<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Budget expenses read once per analytic axis.
 *
 * An axis is a facet of a split, not a bucket: one split may carry several of
 * them, so the axis rows deliberately do not add up to budget expenses. They
 * answer "how much of the month was essential", never "how was the month
 * partitioned".
 *
 * An axis with no split is an exact zero, because the month really did hold
 * nothing on it. An axis that cannot be computed at all — no account, mixed
 * assets — carries the same reason the rest of the projection carries, never a
 * zero standing in for an absence.
 */
final class MonthlyAxisExpenseCalculator
{
    /**
     * @param list<string>          $accountAssets
     * @param list<MonthlyMovement> $movements                booked, live source movements only
     * @param array<string, bool>   $budgetIncludedByCategory
     * @param list<string>          $axes                     every published analytic axis
     *
     * @return array<string, MonthlyMetric> keyed by axis, in the order given
     */
    public static function compute(
        array $accountAssets,
        array $movements,
        array $budgetIncludedByCategory,
        array $axes,
    ): array {
        $signed = array_fill_keys($axes, DecimalValue::zero());
        $sources = array_fill_keys($axes, []);
        foreach ($movements as $movement) {
            if (!MonthlyProjectionCalculator::contributesToBudgetExpenses($movement->kind)) {
                continue;
            }
            foreach ($movement->splits as $split) {
                if (!($budgetIncludedByCategory[$split->categoryId] ?? false)) {
                    continue;
                }
                foreach ($split->analyticAxes as $axis) {
                    if (!array_key_exists($axis, $signed)) {
                        continue;
                    }
                    $signed[$axis] = ExactDecimal::add($signed[$axis], $split->amount);
                    if ('' !== $movement->transactionId) {
                        $sources[$axis][$movement->transactionId] = true;
                    }
                }
            }
        }

        $assets = array_values(array_unique($accountAssets));
        $reason = match (true) {
            count($assets) > 1 => MonthlyProjectionReason::MIXED_ASSETS,
            [] === $assets => MonthlyProjectionReason::NO_ACCOUNT,
            default => null,
        };
        if (null === $reason) {
            $asset = AssetCode::fromString($assets[0]);
            foreach ($movements as $movement) {
                if (MonthlyProjectionCalculator::contributesToBudgetExpenses($movement->kind) && !$movement->asset->equals($asset)) {
                    $reason = MonthlyProjectionReason::MIXED_ASSETS;
                    break;
                }
            }
        }

        $metrics = [];
        foreach ($axes as $axis) {
            $metrics[$axis] = null === $reason
                ? MonthlyMetric::value(ExactDecimal::negate($signed[$axis]), AssetCode::fromString($assets[0]), self::sorted($sources[$axis]))
                : MonthlyMetric::missing($reason, self::sorted($sources[$axis]));
        }

        return $metrics;
    }

    /**
     * @param array<string, true> $sources
     *
     * @return list<string>
     */
    private static function sorted(array $sources): array
    {
        $identifiers = array_keys($sources);
        sort($identifiers, SORT_STRING);

        return $identifiers;
    }
}
