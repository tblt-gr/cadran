<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;

/** The two chart datasets that need more than a column of the table. Every figure is an exact backend decimal. */
final class AnnualCharts
{
    public const int TOP_CATEGORIES = 6;
    private const string NO_MOVEMENTS = 'NO_MOVEMENTS';

    /** @param list<AnnualMonth> $months */
    public static function topExpenseCategories(array $months, AnnualCatalogue $catalogue, Aggregate $budgetExpenses): AnnualTopCategoriesView
    {
        $total = $budgetExpenses->total;
        $asset = $budgetExpenses->asset?->toString();
        if (null === $total) {
            return new AnnualTopCategoriesView([], null, $budgetExpenses->totalReason?->value, $asset);
        }
        if (ExactDecimal::isZero($total)) {
            return new AnnualTopCategoriesView([], null, AggregateReason::ZERO_DENOMINATOR->value, $asset);
        }

        $amounts = [];
        foreach ($months as $month) {
            $figures = $month->figures;
            if (null === $figures) {
                continue;
            }
            foreach ($figures->budgetExpenseCategoryIds as $id) {
                $cell = $figures->cells['category:'.$id] ?? null;
                if (null === $cell?->value) {
                    // No cell, or no movement, means nothing was spent; any other reason is a gap.
                    if (null === $cell || self::NO_MOVEMENTS === $cell->reason) {
                        continue;
                    }

                    return new AnnualTopCategoriesView([], null, AggregateReason::MISSING_MONTH_VALUE->value, $asset);
                }
                $amounts[$id][] = DecimalValue::fromString($cell->value);
            }
        }
        $totals = [];
        foreach ($amounts as $id => $values) {
            $sum = ExactDecimal::sum(...$values);
            if (!ExactDecimal::isZero($sum)) {
                $totals[] = [(string) $id, $sum, $catalogue->categoryLabel((string) $id) ?? AnnualCatalogue::UNKNOWN_LABEL];
            }
        }
        usort($totals, static fn (array $a, array $b): int => [$b[1]->compareTo($a[1]), $a[2], $a[0]] <=> [0, $b[2], $b[0]]);

        $items = [];
        foreach (array_slice($totals, 0, self::TOP_CATEGORIES) as [$id, $sum, $label]) {
            $items[] = new AnnualCategoryShareView($id, $label, $sum->toString(), ExactDecimal::divide($sum, $total)->toString());
        }
        $rest = array_slice($totals, self::TOP_CATEGORIES);
        $other = [] === $rest ? null : self::other($rest, $total);

        return new AnnualTopCategoriesView($items, $other, null, $asset);
    }

    /** @param list<AnnualMonth> $months */
    public static function allocation(array $months, IncompleteMonths $incomplete, AnnualCatalogue $catalogue): AnnualAllocationView
    {
        $last = null;
        foreach ($months as $month) {
            $counted = null !== $month->figures
                && (MonthState::COMPLETE === $month->state || (MonthState::PROVISIONAL === $month->state && IncompleteMonths::INCLUDE === $incomplete));
            if ($counted) {
                $last = $month;
            }
        }
        if (null === $last?->figures) {
            return new AnnualAllocationView(null, null, [], AggregateReason::EMPTY_POPULATION->value);
        }

        $items = [];
        $asset = null;
        foreach ($last->figures->allocation as $item) {
            $asset ??= $item->asset;
            $items[] = new AnnualAllocationItemView(
                $item->groupId,
                $catalogue->groupLabel($item->groupId) ?? AnnualCatalogue::UNKNOWN_LABEL,
                $item->value,
                $item->share,
                null === $item->value ? ($item->reason ?? AnnualColumnSeries::NOT_IN_PROJECTION) : null,
            );
        }

        return new AnnualAllocationView($last->figures->netWorthAsOf, $asset, $items, [] === $items ? AggregateReason::EMPTY_POPULATION->value : null);
    }

    /** @param list<AnnualMonth> $months */
    public static function flows(array $months): AnnualFlowsView
    {
        $columns = [];
        foreach (['cashIncome', 'budgetExpenses', 'budgetSurplus'] as $id) {
            $columns[$id] = new AnnualColumn($id, $id, ColumnKind::FLOW, true);
        }
        $points = [];
        foreach ($months as $month) {
            $points[] = new AnnualFlowMonthView(
                $month->month->key(),
                AnnualColumnSeries::cell($columns['cashIncome'], $month),
                AnnualColumnSeries::cell($columns['budgetExpenses'], $month),
                AnnualColumnSeries::cell($columns['budgetSurplus'], $month),
            );
        }

        return new AnnualFlowsView(self::assetOf($months, ['cashIncome', 'budgetExpenses', 'budgetSurplus']), $points);
    }

    /** @param list<AnnualMonth> $months */
    public static function netWorth(array $months): AnnualNetWorthView
    {
        $column = new AnnualColumn('endNetWorth', 'endNetWorth', ColumnKind::STOCK, true);
        $points = [];
        foreach ($months as $month) {
            $points[] = new AnnualNetWorthPointView($month->month->key(), AnnualColumnSeries::cell($column, $month));
        }

        return new AnnualNetWorthView(self::assetOf($months, ['endNetWorth']), $points);
    }

    /**
     * @param list<AnnualMonth> $months
     * @param list<string>      $ids
     */
    private static function assetOf(array $months, array $ids): ?string
    {
        foreach ($months as $month) {
            foreach ($ids as $id) {
                $asset = $month->figures->cells[$id]->assetCode ?? null;
                if (null !== $asset) {
                    return $asset;
                }
            }
        }

        return null;
    }

    /** @param list<array{string, DecimalValue, string}> $rest */
    private static function other(array $rest, DecimalValue $total): AnnualCategoryShareView
    {
        $sum = ExactDecimal::sum(...array_map(static fn (array $entry): DecimalValue => $entry[1], $rest));

        return new AnnualCategoryShareView(null, 'Autres', $sum->toString(), ExactDecimal::divide($sum, $total)->toString());
    }
}
