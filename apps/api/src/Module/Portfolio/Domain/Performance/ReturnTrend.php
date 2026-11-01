<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;

/**
 * The chained monthly return of a trailing window: the base-100 index at each
 * month end and the cumulative return of the whole window.
 *
 * The cumulative return comes from the unrounded product of the growth
 * factors, never from the two-decimal display index.
 */
final readonly class ReturnTrend
{
    public const int MONTHS = 12;
    private const int SCALE = 24;
    private const int CUMULATIVE_SCALE = 6;

    /**
     * @param list<array{month: string, index: ?string}> $months
     */
    private function __construct(
        public array $months,
        public ?string $from,
        public string $to,
        public ?string $cumulativeReturn,
        public ?ReturnTrendReason $reason,
        public ?string $reasonMonth,
    ) {
    }

    /**
     * The twelve calendar months strictly before the month of $today, as `YYYY-MM`.
     *
     * @return list<string>
     */
    public static function windowBefore(\DateTimeImmutable $today): array
    {
        $first = $today->modify('first day of this month');
        $window = [];
        for ($offset = self::MONTHS; $offset >= 1; --$offset) {
            $window[] = $first->modify(sprintf('-%d months', $offset))->format('Y-m');
        }

        return $window;
    }

    /**
     * A trend that cannot be computed at all: the window stays, every index is null.
     *
     * @param list<string> $months the window, oldest first
     */
    public static function unavailable(array $months, ReturnTrendReason $reason): self
    {
        if (self::MONTHS !== count($months)) {
            throw new \InvalidArgumentException('A return trend reads exactly twelve months.');
        }

        return new self(self::months($months, array_fill(0, self::MONTHS, null)), null, $months[self::MONTHS - 1], null, $reason, null);
    }

    /**
     * @param list<string>  $months     the window, oldest first
     * @param list<?string> $rates      the unrounded monthly rate of each month, null where it has none
     * @param int           $firstIndex position of the chain start in the window; below zero starts at the first month, at or beyond the window length means no history
     */
    public static function compute(array $months, array $rates, int $firstIndex): self
    {
        if (self::MONTHS !== count($months) || count($rates) !== count($months)) {
            throw new \InvalidArgumentException('A return trend reads exactly twelve months and one rate per month.');
        }

        $to = $months[self::MONTHS - 1];
        $start = max(0, $firstIndex);
        if ($start >= self::MONTHS) {
            return new self(self::months($months, array_fill(0, self::MONTHS, null)), null, $to, null, ReturnTrendReason::NO_HISTORY, null);
        }

        $indexes = ChainedIndex::series($rates, $start);
        $from = null;
        foreach ($indexes as $position => $index) {
            if (null !== $index) {
                $from = $months[$position];

                break;
            }
        }

        $product = CalculatedDecimal::one();
        foreach (array_slice($rates, $start, null, true) as $position => $rate) {
            $factor = null === $rate ? null : CalculatedDecimal::one()->add(CalculatedDecimal::fromDecimal(DecimalValue::fromString($rate)));
            if (null === $factor || $factor->compareTo(CalculatedDecimal::zero()) <= 0) {
                return new self(
                    self::months($months, $indexes),
                    $from,
                    $to,
                    null,
                    null === $factor ? ReturnTrendReason::MISSING_MONTH : ReturnTrendReason::NON_POSITIVE_GROWTH_FACTOR,
                    $months[$position],
                );
            }
            $product = $product->proportion($factor, CalculatedDecimal::one(), self::SCALE, RoundingMode::HALF_EVEN);
        }

        $cumulative = $product->subtract(CalculatedDecimal::one())->round(self::CUMULATIVE_SCALE, RoundingMode::HALF_EVEN);

        return new self(self::months($months, $indexes), $from, $to, $cumulative->toString(), null, null);
    }

    /**
     * @param list<string>  $months
     * @param list<?string> $indexes
     *
     * @return list<array{month: string, index: ?string}>
     */
    private static function months(array $months, array $indexes): array
    {
        $list = [];
        foreach ($months as $position => $month) {
            $list[] = ['month' => $month, 'index' => $indexes[$position]];
        }

        return $list;
    }
}
