<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/** Median and extremes of month values; the earliest month wins a tie. */
final readonly class Distribution
{
    private function __construct(
        public ?DecimalValue $median,
        public ?MonthExtreme $minimum,
        public ?MonthExtreme $maximum,
        public ?AggregateReason $reason,
    ) {
    }

    /** @param list<MonthValue> $months */
    public static function of(array $months): self
    {
        if ([] === $months) {
            return new self(null, null, null, AggregateReason::EMPTY_POPULATION);
        }

        usort($months, static fn (MonthValue $left, MonthValue $right): int => $left->month->key() <=> $right->month->key());
        $minimum = null;
        $maximum = null;
        $values = [];
        foreach ($months as $month) {
            if (null === $month->value) {
                return new self(null, null, null, AggregateReason::MISSING_MONTH_VALUE);
            }
            $values[] = $month->value;
            $minimum = null === $minimum || $month->value->compareTo($minimum->value) < 0 ? new MonthExtreme($month->value, $month->month) : $minimum;
            $maximum = null === $maximum || $month->value->compareTo($maximum->value) > 0 ? new MonthExtreme($month->value, $month->month) : $maximum;
        }

        usort($values, static fn (DecimalValue $left, DecimalValue $right): int => $left->compareTo($right));
        $middle = intdiv(count($values), 2);
        $median = 1 === count($values) % 2
            ? $values[$middle]
            : ExactDecimal::divide(ExactDecimal::add($values[$middle - 1], $values[$middle]), DecimalValue::fromString('2'));

        return new self($median, $minimum, $maximum, null);
    }
}
