<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * One calendar month of a workspace.
 *
 * Booking dates are business days, not instants, so the month a movement
 * belongs to is read straight from its date: no timezone can move it across a
 * boundary.
 */
final readonly class CalendarMonth
{
    public const int MIN_YEAR = 1900;
    public const int MAX_YEAR = 2999;

    public function __construct(public int $year, public int $month)
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR || $month < 1 || $month > 12) {
            throw new InvalidCalendarMonth('A calendar month needs a year between 1900 and 2999 and a month between 1 and 12.');
        }
    }

    public static function containing(\DateTimeInterface $day): self
    {
        return new self((int) $day->format('Y'), (int) $day->format('n'));
    }

    /** Parses `YYYY-MM`. */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $value, $parts)) {
            throw new InvalidCalendarMonth('A calendar month is written YYYY-MM.');
        }

        return new self((int) $parts[1], (int) $parts[2]);
    }

    public function firstDay(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month), new \DateTimeZone('UTC'));
    }

    public function lastDay(): \DateTimeImmutable
    {
        return $this->firstDay()->modify('last day of this month');
    }

    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }
}
