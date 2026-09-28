<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\RoundingMode;

/**
 * The twelve calendar-month parts of one annual goal version. Whatever the
 * mode, the parts are never negative and always sum exactly to the target.
 */
final readonly class GoalSchedule
{
    public const int MONTHS = 12;

    /** @param list<DecimalValue> $parts */
    private function __construct(public GoalScheduleMode $mode, public array $parts)
    {
    }

    /**
     * Parts 1 to 11 are T / 12 rounded HALF_UP at the display precision; the
     * residual lands on December, so the split is deterministic and exact.
     */
    public static function linear(DecimalValue $target, int $displayPrecision): self
    {
        $target = self::atPrecision($target, $displayPrecision);
        // Dividing straight at the display precision rounds once, HALF_UP,
        // which is what dividing then rounding would give without the risk of
        // a double rounding.
        $part = ExactDecimal::divide($target, DecimalValue::fromString((string) self::MONTHS), $displayPrecision);
        $december = ExactDecimal::subtract($target, ExactDecimal::multiply($part, DecimalValue::fromString((string) (self::MONTHS - 1))));
        if ($december->isNegative()) {
            throw new InvalidGoalSchedule('linear_schedule_impossible', 'This target is too small for a linear split; enter the twelve parts instead.');
        }

        return self::verified(GoalScheduleMode::LINEAR, $target, [...array_fill(0, self::MONTHS - 1, $part), self::atPrecision($december, $displayPrecision)]);
    }

    /** @param list<DecimalValue> $parts */
    public static function custom(DecimalValue $target, array $parts, int $displayPrecision): self
    {
        self::assertShape($parts);

        return self::verified(GoalScheduleMode::CUSTOM, $target, array_map(static fn (DecimalValue $part): DecimalValue => self::atPrecision($part, $displayPrecision), $parts));
    }

    /**
     * Rebuilds a stored schedule. The precision was checked when it was
     * written, but the count, signs and sum are checked again so a corrupted
     * row set never reaches a screen as if it were exact.
     *
     * @param list<DecimalValue> $parts
     */
    public static function restore(GoalScheduleMode $mode, DecimalValue $target, array $parts): self
    {
        self::assertShape($parts);

        return self::verified($mode, $target, $parts);
    }

    /** @return list<DecimalValue> the exact running total after each month */
    public function cumulative(): array
    {
        $running = DecimalValue::zero();
        $totals = [];
        foreach ($this->parts as $part) {
            $running = ExactDecimal::add($running, $part);
            $totals[] = $running;
        }

        return $totals;
    }

    public function sameAs(self $other): bool
    {
        if ($this->mode !== $other->mode) {
            return false;
        }
        foreach ($this->parts as $index => $part) {
            if (0 !== $part->compareTo($other->parts[$index])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<DecimalValue> $parts */
    private static function assertShape(array $parts): void
    {
        if (self::MONTHS !== count($parts)) {
            throw new InvalidGoalSchedule('schedule_part_count', 'A goal schedule has exactly twelve monthly parts.');
        }
        foreach ($parts as $part) {
            if ($part->isNegative()) {
                throw new InvalidGoalSchedule('schedule_negative_part', 'A goal schedule part is zero or positive.');
            }
        }
    }

    /** @param list<DecimalValue> $parts */
    private static function verified(GoalScheduleMode $mode, DecimalValue $target, array $parts): self
    {
        $actual = ExactDecimal::sum(...$parts);
        if (0 !== $actual->compareTo($target)) {
            throw new InvalidGoalSchedule('schedule_sum_mismatch', 'The twelve parts must sum exactly to the target.', ['expected' => $target->toString(), 'actual' => $actual->toString(), 'difference' => ExactDecimal::subtract($target, $actual)->toString()]);
        }

        return new self($mode, $parts);
    }

    /**
     * Restates a figure at exactly the display precision, refusing any
     * non-zero digit beyond it rather than rounding it away.
     */
    private static function atPrecision(DecimalValue $value, int $precision): DecimalValue
    {
        $restated = ExactDecimal::round($value, $precision, RoundingMode::DOWN);
        if (0 !== $restated->compareTo($value)) {
            throw new InvalidGoalSchedule('schedule_precision_exceeded', sprintf('A goal schedule uses at most %d decimal places.', $precision));
        }

        return $restated;
    }
}
