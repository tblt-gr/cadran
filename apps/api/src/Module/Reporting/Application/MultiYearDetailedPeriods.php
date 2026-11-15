<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\AggregateQuality;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\AggregationPopulation;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Reporting\Domain\Aggregation\ResponseDistribution;
use App\Module\Reporting\Domain\Aggregation\ResponseExtreme;
use App\Module\Reporting\Domain\Aggregation\ResponseRateAggregator;

/**
 * The whole-range detailed aggregates of the all-years report, one per column.
 */
final readonly class MultiYearDetailedPeriods
{
    /**
     * The whole selected detailed period, one aggregate per column, over every month of every year
     * of the range (not only the `COMPLETE` years the summary row is restricted to). Unlike a
     * single year's aggregate, this total is never stored: it is computed with the unbounded
     * `ExactDecimal::*ForResponse` arithmetic already used by the "Moyenne des années complètes"
     * summary row, because summing several years of near-maximum monthly figures can legitimately
     * exceed the `NUMERIC(50,24)` storage width that {@see ReportAggregator} enforces for a single
     * year. A column whose months mix policy versions or assets is `null` with the same
     * `MIXED_METRIC_POLICIES`/`MIXED_ASSETS` reason a single year's aggregate would carry.
     *
     * @param list<AnnualColumn>             $columns
     * @param array<int, ComputedAnnualYear> $computedByYear
     *
     * @return array<string, MultiYearDetailedAggregateView>
     */
    public function detailedPeriod(array $columns, array $computedByYear, int $from, int $to, IncompleteMonths $incomplete): array
    {
        $allMonths = [];
        for ($year = $from; $year <= $to; ++$year) {
            foreach ($computedByYear[$year]->months as $month) {
                $allMonths[] = $month;
            }
        }

        $catalogue = $computedByYear[$to]->catalogue;
        $detailed = [];
        foreach ($columns as $column) {
            $months = self::monthsForColumn($column, $allMonths, $catalogue);
            $detailed[$column->id] = ColumnKind::RATE === $column->kind
                ? self::detailedRate($column, $months, $incomplete)
                : self::detailedAmount($column, $months, $incomplete);
        }

        return $detailed;
    }

    /**
     * A month before an `account:*` column's account opened never enters its whole-period
     * aggregate, the same way {@see MultiYearSummary::figureFor()} nulls a single year the account was not open for:
     * without this filter, a month with no figure for the not-yet-open account would surface as a
     * missing value for the whole population instead of simply being outside it.
     *
     * @param list<AnnualMonth> $months
     *
     * @return list<AnnualMonth>
     */
    public static function monthsForColumn(AnnualColumn $column, array $months, AnnualCatalogue $catalogue): array
    {
        if (!str_starts_with($column->id, 'account:')) {
            return $months;
        }
        $openedOn = $catalogue->accountOpenedOn(substr($column->id, 8));
        if (null === $openedOn) {
            return $months;
        }

        return array_values(array_filter(
            $months,
            static fn (AnnualMonth $month): bool => $openedOn <= $month->month->lastDay()->format('Y-m-d'),
        ));
    }

    /** @param list<AnnualMonth> $months */
    public static function detailedAmount(AnnualColumn $column, array $months, IncompleteMonths $incomplete): MultiYearDetailedAggregateView
    {
        $values = AnnualColumnSeries::values($column->id, $column->known, $months);
        $population = AggregationPopulation::of($values, $incomplete);
        $guard = $population->guard(...$population->totals);
        if (null !== $guard) {
            return self::unavailableDetailed($guard);
        }

        $total = null;
        $totalReason = AggregateReason::NOT_ADDITIVE;
        if (ColumnKind::STOCK !== $column->kind) {
            [$total, $totalReason] = ResponseRateAggregator::sum($population->totals);
        }
        [$average, $averageReason] = self::meanForResponse($population->statistics);
        $distribution = ResponseDistribution::of($population->statistics);

        return new MultiYearDetailedAggregateView(
            $total, $totalReason?->value, $average, $averageReason?->value,
            $distribution->median, $distribution->reason?->value,
            self::detailedExtreme($distribution->minimum), self::detailedExtreme($distribution->maximum), $distribution->reason?->value,
            $population->quality(...$population->totals)->value,
        );
    }

    /** @param list<AnnualMonth> $months */
    public static function detailedRate(AnnualColumn $column, array $months, IncompleteMonths $incomplete): MultiYearDetailedAggregateView
    {
        $inputs = AnnualColumnSeries::rateInputs($column, $months);
        $denominatorByMonth = self::byMonthKey($inputs->denominator);
        $population = AggregationPopulation::of($inputs->numerator, $incomplete);
        $totalDenominators = self::matching($population->totals, $denominatorByMonth);
        $guard = $population->guard(...$population->totals, ...$totalDenominators);
        if (null !== $guard) {
            return self::unavailableDetailed($guard);
        }

        [$total, $totalReason] = ResponseRateAggregator::ratioOfSums($population->totals, $totalDenominators);
        [$average, $averageReason] = ResponseRateAggregator::ratioOfSums($population->statistics, self::matching($population->statistics, $denominatorByMonth));

        $ratesByMonth = self::byMonthKey(AnnualColumnSeries::values($column->id, true, $months));
        $usable = [];
        foreach (self::matching($population->statistics, $ratesByMonth) as $rate) {
            if (null !== $rate->value) {
                $usable[] = $rate;
            }
        }
        $distribution = ResponseDistribution::of($usable);

        return new MultiYearDetailedAggregateView(
            $total, $totalReason?->value, $average, $averageReason?->value,
            $distribution->median, $distribution->reason?->value,
            self::detailedExtreme($distribution->minimum), self::detailedExtreme($distribution->maximum), $distribution->reason?->value,
            $population->quality(...$population->totals, ...$totalDenominators)->value,
        );
    }

    /**
     * @param list<MonthValue> $months
     *
     * @return array{?string, ?AggregateReason}
     */
    public static function meanForResponse(array $months): array
    {
        [$sum, $reason] = ResponseRateAggregator::sum($months);
        if (null === $sum) {
            return [null, $reason];
        }

        return [ExactDecimal::divideForResponse($sum, (string) count($months)), null];
    }

    public static function unavailableDetailed(AggregateReason $reason): MultiYearDetailedAggregateView
    {
        // Mirrors ReportAggregator::unavailable(): an empty population truly has nothing, whereas a
        // mixed-policy or mixed-asset population still has months, just none that agree.
        $quality = AggregateReason::EMPTY_POPULATION === $reason ? AggregateQuality::EMPTY : AggregateQuality::PARTIAL;

        return new MultiYearDetailedAggregateView(null, $reason->value, null, $reason->value, null, $reason->value, null, null, $reason->value, $quality->value);
    }

    public static function detailedExtreme(?ResponseExtreme $extreme): ?MultiYearDetailedExtremeView
    {
        return null === $extreme ? null : new MultiYearDetailedExtremeView($extreme->value, $extreme->month->key());
    }

    /**
     * @param list<MonthValue> $months
     *
     * @return array<string, MonthValue>
     */
    public static function byMonthKey(array $months): array
    {
        $indexed = [];
        foreach ($months as $month) {
            $indexed[$month->month->key()] = $month;
        }

        return $indexed;
    }

    /**
     * @param list<MonthValue>          $months
     * @param array<string, MonthValue> $byMonth
     *
     * @return list<MonthValue>
     */
    public static function matching(array $months, array $byMonth): array
    {
        $matching = [];
        foreach ($months as $month) {
            $matching[] = $byMonth[$month->month->key()];
        }

        return $matching;
    }
}
