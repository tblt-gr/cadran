<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Foundation\Domain\ExactDecimal;

/** Exact ratio-of-sums whose response-only intermediate values may exceed NUMERIC(50,24). */
final class ResponseRateAggregator
{
    /** @return array{?string, ?AggregateReason} */
    public static function rateOfSums(RateInputs $inputs, IncompleteMonths $incomplete): array
    {
        self::assertBounded($inputs->numerator);
        self::assertBounded($inputs->denominator);
        if (self::keys($inputs->numerator) !== self::keys($inputs->denominator)) {
            throw new \InvalidArgumentException('A rate needs its numerator and denominator over the same months.');
        }

        $denominator = self::byMonth($inputs->denominator);
        foreach ($inputs->numerator as $month) {
            if ($denominator[$month->month->key()]->state !== $month->state) {
                throw new \InvalidArgumentException('A rate needs its numerator and denominator in the same state for each month.');
            }
        }
        $population = AggregationPopulation::of($inputs->numerator, $incomplete);
        $numerators = $population->totals;
        $denominators = self::matching($numerators, $denominator);
        $guard = $population->guard(...$numerators, ...$denominators);
        if (null !== $guard) {
            return [null, $guard];
        }

        [$numerator, $reason] = self::sum($numerators);
        [$denominator, $denominatorReason] = self::sum($denominators);
        if (null === $numerator || null === $denominator) {
            return [null, $reason ?? $denominatorReason];
        }
        if (ExactDecimal::isZeroForResponse($denominator)) {
            return [null, AggregateReason::ZERO_DENOMINATOR];
        }
        if (ExactDecimal::compareForResponse($denominator, '0') < 0) {
            return [null, AggregateReason::NEGATIVE_DENOMINATOR];
        }

        return [ExactDecimal::divideForResponse($numerator, $denominator), null];
    }

    /**
     * @param list<MonthValue> $months
     *
     * @return array{?string, ?AggregateReason}
     */
    private static function sum(array $months): array
    {
        if ([] === $months) {
            return [null, AggregateReason::EMPTY_POPULATION];
        }
        $sum = '0';
        foreach ($months as $month) {
            if (null === $month->value) {
                return [null, AggregateReason::MISSING_MONTH_VALUE];
            }
            $sum = ExactDecimal::addForResponse($sum, $month->value->toString());
        }

        return [$sum, null];
    }

    /** @param list<MonthValue> $months */
    private static function assertBounded(array $months): void
    {
        if (count($months) > ReportAggregator::MAX_MONTHS) {
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
     * @param list<MonthValue>          $months
     * @param array<string, MonthValue> $byMonth
     *
     * @return list<MonthValue>
     */
    private static function matching(array $months, array $byMonth): array
    {
        return array_map(static fn (MonthValue $month): MonthValue => $byMonth[$month->month->key()], $months);
    }
}
