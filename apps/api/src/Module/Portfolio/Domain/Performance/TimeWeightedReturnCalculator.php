<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Domain\PortfolioOperation;

/** Flow-day values are end-of-day observations: subtract the net flow before computing each return. */
final class TimeWeightedReturnCalculator
{
    public const int MAX_SUBPERIODS = ReturnChain::MAX_SUBPERIODS;
    public const int MAX_RESPONSE_SUBPERIODS = 400;

    /**
     * @param list<PortfolioOperation> $operations
     * @param list<PeriodAnchor>       $valuations   active COMPLETE observations only
     * @param list<PeriodAnchor>       $monthAnchors monthly boundaries for YEAR and INCEPTION
     */
    public function calculate(PeriodAnchor $start, PeriodAnchor $end, array $operations, array $valuations, array $monthAnchors, int $displayPrecision): TimeWeightedReturn
    {
        $startDate = $start->date->format('Y-m-d');
        $endDate = $end->date->format('Y-m-d');
        $flows = [];
        $transfers = [];
        foreach ($operations as $operation) {
            $date = $operation->details->tradeDate->format('Y-m-d');
            if ($date <= $startDate || $date > $endDate) {
                continue;
            }
            if (ExternalFlow::isUnvalued($operation)) {
                $transfers[] = $date;
            }
            if (null !== ($amount = ExternalFlow::signedAmount($operation))) {
                $flows[$date] = ($flows[$date] ?? CalculatedDecimal::zero())->add(CalculatedDecimal::fromDecimal($amount));
            }
        }
        $flows = array_filter($flows, static fn (CalculatedDecimal $flow): bool => !$flow->isZero());
        $available = [];
        foreach ([...$valuations, $start, $end] as $anchor) {
            $available[$anchor->date->format('Y-m-d')] = $anchor;
        }
        $dates = array_fill_keys(array_keys($flows), true);
        foreach ($monthAnchors as $anchor) {
            $date = $anchor->date->format('Y-m-d');
            if ($date > $startDate && $date < $endDate) {
                $dates[$date] = true;
                $available[$date] = $anchor;
            }
        }
        $dates[$endDate] = true;
        ksort($dates);
        $count = count($dates);
        if ($count > self::MAX_SUBPERIODS) {
            return new TimeWeightedReturn(null, [], $count, 0, [new PerformanceFinding(PerformanceReason::TOO_MANY_SUBPERIODS)], []);
        }
        $missing = [];
        $stale = [];
        $warnings = [];
        foreach (array_unique([$startDate, ...array_keys($dates)]) as $date) {
            $anchor = $available[$date] ?? null;
            if (null === $anchor) {
                $missing[] = $date;
                continue;
            }
            foreach ($anchor->flags as $flag) {
                if ('STALE_PRICES' === $flag) {
                    $stale[] = $date;
                } else {
                    $warnings[] = new PerformanceFinding(PerformanceReason::from($flag), [$date]);
                }
            }
        }
        $blocking = [];
        foreach ([[PerformanceReason::MISSING_FLOW_VALUATION, $missing], [PerformanceReason::STALE_FLOW_VALUATION, $stale], [PerformanceReason::UNVALUED_SECURITY_TRANSFER, $transfers]] as [$reason, $reasonDates]) {
            if ([] !== $reasonDates) {
                $blocking[] = new PerformanceFinding($reason, $reasonDates);
            }
        }
        $missingCount = count(array_unique([...$missing, ...$stale]));
        if ([] !== $blocking) {
            return new TimeWeightedReturn(null, [], $count, $missingCount, $blocking, $warnings, [$start, ...array_values(array_intersect_key($available, $dates))]);
        }
        $previous = $start;
        $rates = [];
        $subperiods = [];
        foreach (array_keys($dates) as $date) {
            $anchor = $available[$date];
            $flow = $flows[$date] ?? CalculatedDecimal::zero();
            $startValue = CalculatedDecimal::fromDecimal($previous->totalValue);
            $endValue = CalculatedDecimal::fromDecimal($anchor->totalValue);
            $adjusted = $endValue->subtract($flow);
            $rate = null;
            if ($startValue->isZero()) {
                if ($adjusted->isZero()) {
                    $rate = CalculatedDecimal::zero();
                } else {
                    $blocking[] = new PerformanceFinding(PerformanceReason::ZERO_START_VALUE, [$previous->date->format('Y-m-d')]);
                }
            } else {
                $rate = $adjusted->proportion(CalculatedDecimal::one(), $startValue, 24, RoundingMode::HALF_EVEN)->subtract(CalculatedDecimal::one());
            }
            if (null !== $rate) {
                $rates[] = $rate;
            }
            if (count($subperiods) < self::MAX_RESPONSE_SUBPERIODS) {
                $subperiods[] = [
                    'from' => $previous->date->format('Y-m-d'), 'to' => $date,
                    'startValue' => PerformanceAmount::canonical($startValue, $displayPrecision),
                    'endValue' => PerformanceAmount::canonical($endValue, $displayPrecision),
                    'flow' => PerformanceAmount::canonical($flow, $displayPrecision),
                    'value' => null === $rate ? null : self::display($rate),
                ];
            }
            $previous = $anchor;
        }

        return new TimeWeightedReturn([] === $blocking ? ReturnChain::chain($rates, self::MAX_SUBPERIODS) : null, $subperiods, $count, 0, $blocking, $warnings, [$start, ...array_map(static fn (string $date): PeriodAnchor => $available[$date], array_keys($dates))]);
    }

    public static function display(CalculatedDecimal $rate): string
    {
        return PerformanceAmount::canonical($rate->round(6, RoundingMode::HALF_UP), 6);
    }
}
