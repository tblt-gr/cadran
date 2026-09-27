<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Median and extremes of month values whose figure is response-only and may exceed the
 * NUMERIC(50,24) storage width, for instance when a total spans several years
 * ({@see \App\Module\Reporting\Application\ReadMultiYearReport}). Same algorithm as
 * {@see Distribution}, on canonical strings compared through {@see ExactDecimal} instead of
 * `DecimalValue`, so it never rejects a value the stored per-month aggregate already accepted. The
 * earliest month wins a tie.
 */
final readonly class ResponseDistribution
{
    private function __construct(
        public ?string $median,
        public ?ResponseExtreme $minimum,
        public ?ResponseExtreme $maximum,
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
            $value = $month->value->toString();
            $values[] = $value;
            $minimum = null === $minimum || ExactDecimal::compareForResponse($value, $minimum->value) < 0 ? new ResponseExtreme($value, $month->month) : $minimum;
            $maximum = null === $maximum || ExactDecimal::compareForResponse($value, $maximum->value) > 0 ? new ResponseExtreme($value, $month->month) : $maximum;
        }

        usort($values, static fn (string $left, string $right): int => ExactDecimal::compareForResponse($left, $right));
        $middle = intdiv(count($values), 2);
        $median = 1 === count($values) % 2
            ? $values[$middle]
            : ExactDecimal::divideForResponse(ExactDecimal::addForResponse($values[$middle - 1], $values[$middle]), '2');

        return new self($median, $minimum, $maximum, null);
    }
}
