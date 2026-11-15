<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;

final class GoalCalendar
{
    /**
     * The month a status is read at when none is chosen: December of a past year, the current
     * month of the current year and January of a future year, which is itself a future month.
     */
    public static function defaultMonth(CalendarMonth $today, int $year): CalendarMonth
    {
        return match (true) {
            $year < $today->year => new CalendarMonth($year, 12),
            $year === $today->year => $today,
            default => new CalendarMonth($year, 1),
        };
    }

    public static function isFuture(CalendarMonth $month, CalendarMonth $today): bool
    {
        return self::ordinal($month) > self::ordinal($today);
    }

    public static function ordinal(CalendarMonth $month): int
    {
        return $month->year * 12 + $month->month;
    }
}
