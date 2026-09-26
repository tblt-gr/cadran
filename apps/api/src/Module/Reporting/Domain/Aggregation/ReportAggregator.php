<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * One exact aggregation engine for every report column: totals, averages, medians and extremes
 * over month values. It computes nothing on floats and rounds only at the named division scale.
 * A figure that cannot be computed is null with a reason, never zero.
 */
final class ReportAggregator
{
    public const int MAX_MONTHS = 240;

    private const string FLOW_FORMULA = 'total = sum of monthly values over totals months; average, median, minimum and maximum over counted months';
    private const string STOCK_FORMULA = 'no total; period end = value of the latest totals month; average, median, minimum and maximum of month-end values over counted months';
    private const string RATE_FORMULA = 'total and average = sum of monthly numerators / sum of monthly denominators; median, minimum and maximum of monthly rates over counted months';

    /** @param list<MonthValue> $months */
    public static function flow(array $months, IncompleteMonths $incomplete): Aggregate
    {
        return self::amounts(ColumnKind::FLOW, $months, $incomplete);
    }

    /** @param list<MonthValue> $months */
    public static function stock(array $months, IncompleteMonths $incomplete): Aggregate
    {
        return self::amounts(ColumnKind::STOCK, $months, $incomplete);
    }

    /** @param list<MonthValue> $monthlyRates the rate of each month, null when its denominator is zero */
    public static function rate(RateInputs $inputs, array $monthlyRates, IncompleteMonths $incomplete): Aggregate
    {
        self::assertBounded($inputs->numerator);
        self::assertBounded($inputs->denominator);
        self::assertBounded($monthlyRates);
        $keys = self::keys($inputs->numerator);
        if ($keys !== self::keys($inputs->denominator) || $keys !== self::keys($monthlyRates)) {
            throw new \InvalidArgumentException('A rate needs its numerator, denominator and monthly rates over the same months.');
        }

        $denominator = self::byMonth($inputs->denominator);
        foreach ($inputs->numerator as $month) {
            if ($denominator[$month->month->key()]->state !== $month->state) {
                throw new \InvalidArgumentException('A rate needs its numerator and denominator in the same state for each month.');
            }
        }
        $population = AggregationPopulation::of($inputs->numerator, $incomplete);
        $rates = self::byMonth($monthlyRates);
        $numerators = $population->totals;
        $counted = $population->statistics;
        $guard = $population->guard(...$numerators, ...self::matching($numerators, $denominator));
        if (null !== $guard) {
            return self::unavailable(ColumnKind::RATE, $guard, $population, $incomplete, self::RATE_FORMULA);
        }

        [$total, $totalReason] = self::ratio($numerators, self::matching($numerators, $denominator));
        [$average, $averageReason] = self::ratio($counted, self::matching($counted, $denominator));

        $excluded = $population->excluded;
        $usable = [];
        foreach ($counted as $month) {
            $rate = $rates[$month->month->key()];
            if (null === $rate->value) {
                $excluded[] = new ExcludedMonth($month->month, ExcludedReason::NULL_VALUE);
            } else {
                $usable[] = $rate;
            }
        }
        $distribution = Distribution::of($usable);

        return new Aggregate(
            ColumnKind::RATE, $total, $totalReason, $average, $averageReason,
            $distribution->median, $distribution->reason, $distribution->minimum, $distribution->maximum, $distribution->reason,
            null, $population->asset(), $population->policyVersion(),
            self::months($counted), self::months($numerators), $excluded, $incomplete,
            $population->quality(...$numerators, ...self::matching($numerators, $denominator)), self::RATE_FORMULA,
        );
    }

    /**
     * The state of every month of a range, keyed by `YYYY-MM`, from the workspace-local day.
     *
     * @return array<string, MonthState>
     */
    public static function monthStates(CalendarMonth $first, CalendarMonth $last, ?CalendarMonth $firstDataMonth, \DateTimeImmutable $today): array
    {
        $count = self::ordinal($last) - self::ordinal($first) + 1;
        if ($count > self::MAX_MONTHS) {
            throw AggregationPopulationTooLarge::of($count);
        }

        $current = self::ordinal(CalendarMonth::containing($today));
        $start = null === $firstDataMonth ? null : self::ordinal($firstDataMonth);
        $states = [];
        for ($ordinal = self::ordinal($first); $ordinal <= self::ordinal($last); ++$ordinal) {
            $month = new CalendarMonth(intdiv($ordinal, 12), $ordinal % 12 + 1);
            $beforeData = null !== $start && $ordinal < $start;
            $states[$month->key()] = match (true) {
                $ordinal > $current => MonthState::FUTURE,
                $beforeData => MonthState::NO_DATA,
                $ordinal === $current => MonthState::PROVISIONAL,
                null === $start => MonthState::NO_DATA,
                default => MonthState::COMPLETE,
            };
        }

        return $states;
    }

    /** Current minus previous average, null when either is unknown or the policy versions differ. */
    public static function compareAverages(Aggregate $current, Aggregate $previous): ?DecimalValue
    {
        if ($current->kind !== $previous->kind || null !== self::compareAveragesReason($current, $previous) || null === $current->average || null === $previous->average) {
            return null;
        }

        return ExactDecimal::subtract($current->average, $previous->average);
    }

    public static function compareAveragesReason(Aggregate $current, Aggregate $previous): ?AggregateReason
    {
        if (null !== $current->policyVersion && null !== $previous->policyVersion && $current->policyVersion !== $previous->policyVersion) {
            return AggregateReason::POLICY_MISMATCH;
        }

        $differ = null !== $current->asset && null !== $previous->asset && !$current->asset->equals($previous->asset);

        return $differ ? AggregateReason::MIXED_ASSETS : null;
    }

    /** @param list<MonthValue> $months */
    private static function amounts(ColumnKind $kind, array $months, IncompleteMonths $incomplete): Aggregate
    {
        self::assertBounded($months);
        $population = AggregationPopulation::of($months, $incomplete);
        $formula = ColumnKind::STOCK === $kind ? self::STOCK_FORMULA : self::FLOW_FORMULA;
        $guard = $population->guard(...$population->totals);
        if (null !== $guard) {
            return self::unavailable($kind, $guard, $population, $incomplete, $formula);
        }

        $total = null;
        $totalReason = AggregateReason::NOT_ADDITIVE;
        $periodEnd = null;
        if (ColumnKind::STOCK === $kind) {
            $periodEnd = $population->latest();
        } else {
            [$total, $totalReason] = self::sum($population->totals);
        }

        [$average, $averageReason] = self::sum($population->statistics, true);
        $distribution = Distribution::of($population->statistics);

        return new Aggregate(
            $kind, $total, $totalReason, $average, $averageReason,
            $distribution->median, $distribution->reason, $distribution->minimum, $distribution->maximum, $distribution->reason,
            $periodEnd, $population->asset(), $population->policyVersion(),
            self::months($population->statistics), self::months($population->totals), $population->excluded, $incomplete,
            $population->quality(...$population->totals), $formula,
        );
    }

    private static function unavailable(ColumnKind $kind, AggregateReason $reason, AggregationPopulation $population, IncompleteMonths $incomplete, string $formula): Aggregate
    {
        return new Aggregate(
            $kind, null, $reason, null, $reason, null, $reason, null, null, $reason, null,
            $population->asset(), $population->policyVersion(),
            self::months($population->statistics), self::months($population->totals), $population->excluded, $incomplete,
            AggregateReason::EMPTY_POPULATION === $reason ? AggregateQuality::EMPTY : AggregateQuality::PARTIAL, $formula,
        );
    }

    /**
     * @param list<MonthValue> $months
     *
     * @return array{?DecimalValue, ?AggregateReason}
     */
    private static function sum(array $months, bool $mean = false): array
    {
        if ([] === $months) {
            return [null, AggregateReason::EMPTY_POPULATION];
        }

        $values = [];
        foreach ($months as $month) {
            if (null === $month->value) {
                return [null, AggregateReason::MISSING_MONTH_VALUE];
            }
            $values[] = $month->value;
        }

        $sum = ExactDecimal::sum(...$values);

        return [$mean ? ExactDecimal::divide($sum, DecimalValue::fromString((string) count($values))) : $sum, null];
    }

    /**
     * @param list<MonthValue> $numerators
     * @param list<MonthValue> $denominators
     *
     * @return array{?DecimalValue, ?AggregateReason}
     */
    private static function ratio(array $numerators, array $denominators): array
    {
        [$numerator, $reason] = self::sum($numerators);
        [$denominator, $denominatorReason] = self::sum($denominators);
        if (null === $numerator || null === $denominator) {
            return [null, $reason ?? $denominatorReason];
        }

        if (ExactDecimal::isZero($denominator)) {
            return [null, AggregateReason::ZERO_DENOMINATOR];
        }

        return $denominator->isNegative()
            ? [null, AggregateReason::NEGATIVE_DENOMINATOR]
            : [ExactDecimal::divide($numerator, $denominator), null];
    }

    /**
     * @param list<MonthValue>          $months
     * @param array<string, MonthValue> $byMonth
     *
     * @return list<MonthValue> the entry of $byMonth for each of the months
     */
    private static function matching(array $months, array $byMonth): array
    {
        $matching = [];
        foreach ($months as $month) {
            $matching[] = $byMonth[$month->month->key()];
        }

        return $matching;
    }

    /** @param list<MonthValue> $months */
    private static function assertBounded(array $months): void
    {
        if (count($months) > self::MAX_MONTHS) {
            throw AggregationPopulationTooLarge::of(count($months));
        }
    }

    /**
     * @param list<MonthValue> $months
     *
     * @return list<string>
     */
    private static function keys(array $months): array
    {
        $keys = array_map(static fn (MonthValue $month): string => $month->month->key(), $months);
        sort($keys);

        return $keys;
    }

    /**
     * @param list<MonthValue> $months
     *
     * @return array<string, MonthValue>
     */
    private static function byMonth(array $months): array
    {
        $indexed = [];
        foreach ($months as $month) {
            $indexed[$month->month->key()] = $month;
        }

        return $indexed;
    }

    /**
     * @param list<MonthValue> $months
     *
     * @return list<CalendarMonth>
     */
    private static function months(array $months): array
    {
        return array_map(static fn (MonthValue $month): CalendarMonth => $month->month, $months);
    }

    private static function ordinal(CalendarMonth $month): int
    {
        return $month->year * 12 + $month->month - 1;
    }
}
