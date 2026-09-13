<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

/**
 * The calendar a recurrence expects its instalments on.
 *
 * The requested `day_of_period` is the anchor and is never lost: a short month
 * clamps to its last day, and the month after it returns to the requested day.
 * Keeping the anchor rather than the previous date is what makes a day 31
 * schedule read 31, 28, 31 instead of drifting down to the 28th for good. A
 * weekly rhythm anchors on an ISO weekday instead, and steps by seven days.
 */
final readonly class RecurrenceSchedule
{
    /** Occurrences are generated forward over this many months, and no further. */
    public const int HORIZON_MONTHS = 12;

    /**
     * The weekly anchor date, or the first day of the anchor month for every
     * other rhythm. Monthly-style indices are counted from that month, so the
     * clamping never reads a previously clamped date.
     */
    private function __construct(
        public RecurrenceIntervalKind $intervalKind,
        public int $dayOfPeriod,
        private \DateTimeImmutable $base,
    ) {
    }

    /**
     * The schedule whose first instalment is the earliest one falling on or
     * after `$from`.
     */
    public static function anchoredOn(RecurrenceIntervalKind $intervalKind, int $dayOfPeriod, \DateTimeImmutable $from): self
    {
        if (!$intervalKind->accepts($dayOfPeriod)) {
            throw new InvalidRecurrence('A weekly schedule anchors on an ISO weekday, any other on a day of month.');
        }
        $start = self::midnight($from);

        if (RecurrenceIntervalKind::WEEKLY === $intervalKind) {
            $shift = ($dayOfPeriod - (int) $start->format('N') + 7) % 7;

            return new self($intervalKind, $dayOfPeriod, $start->modify(sprintf('+%d days', $shift)));
        }

        $month = $start->modify('first day of this month');
        if (self::clamp($month, $dayOfPeriod) < $start) {
            $month = $month->modify(sprintf('+%d months', $intervalKind->monthStep()));
        }

        return new self($intervalKind, $dayOfPeriod, $month);
    }

    /** Twelve months of forecast, the only horizon occurrences are generated over. */
    public static function horizon(\DateTimeImmutable $from): \DateTimeImmutable
    {
        return self::midnight($from)->modify(sprintf('+%d months', self::HORIZON_MONTHS));
    }

    public function first(): \DateTimeImmutable
    {
        return $this->on(0);
    }

    public function on(int $index): \DateTimeImmutable
    {
        if ($index < 0) {
            throw new InvalidRecurrence('A schedule index is never negative.');
        }
        if (RecurrenceIntervalKind::WEEKLY === $this->intervalKind) {
            return $this->base->modify(sprintf('+%d days', 7 * $index));
        }

        return self::clamp($this->base->modify(sprintf('+%d months', $this->intervalKind->monthStep() * $index)), $this->dayOfPeriod);
    }

    /**
     * Every instalment from the first up to and including `$horizon`.
     *
     * @return list<\DateTimeImmutable>
     */
    public function through(\DateTimeImmutable $horizon): array
    {
        $limit = self::midnight($horizon);
        $dates = [];
        for ($index = 0; ; ++$index) {
            $date = $this->on($index);
            if ($date > $limit) {
                return $dates;
            }
            $dates[] = $date;
        }
    }

    /** The first instalment strictly after `$date`, whether or not `$date` is one. */
    public function after(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $target = self::midnight($date);
        // The index is estimated rather than scanned so a far future date costs
        // the same as a near one, then walked the last step or two by hand:
        // clamping makes the estimate approximate, never authoritative.
        $index = max(0, $this->estimate($target));
        while ($this->on($index) > $target && $index > 0) {
            --$index;
        }
        while ($this->on($index) <= $target) {
            ++$index;
        }

        return $this->on($index);
    }

    private function estimate(\DateTimeImmutable $target): int
    {
        if (RecurrenceIntervalKind::WEEKLY === $this->intervalKind) {
            return intdiv((int) $this->base->diff($target)->days * ($target < $this->base ? -1 : 1), 7);
        }
        $months = ((int) $target->format('Y') - (int) $this->base->format('Y')) * 12
            + ((int) $target->format('n') - (int) $this->base->format('n'));

        return intdiv($months, $this->intervalKind->monthStep());
    }

    private static function clamp(\DateTimeImmutable $monthStart, int $dayOfPeriod): \DateTimeImmutable
    {
        return $monthStart->setDate(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
            min($dayOfPeriod, (int) $monthStart->format('t')),
        );
    }

    /**
     * A schedule is a calendar, not an instant: reading it in another timezone
     * would move an instalment by a day.
     */
    private static function midnight(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
