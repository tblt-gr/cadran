<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalCalendar;
use PHPUnit\Framework\TestCase;

final class GoalCalendarTest extends TestCase
{
    public function testDefaultMonthIsDecemberOfAPastYear(): void
    {
        self::assertSame('2025-12', GoalCalendar::defaultMonth(new CalendarMonth(2026, 3), 2025)->key());
    }

    public function testDefaultMonthIsTheCurrentMonthOfTheCurrentYear(): void
    {
        self::assertSame('2026-03', GoalCalendar::defaultMonth(new CalendarMonth(2026, 3), 2026)->key());
    }

    public function testDefaultMonthIsJanuaryOfAFutureYear(): void
    {
        self::assertSame('2027-01', GoalCalendar::defaultMonth(new CalendarMonth(2026, 3), 2027)->key());
    }

    public function testIsFutureOnlyAfterTheCurrentMonth(): void
    {
        $today = new CalendarMonth(2026, 12);

        self::assertFalse(GoalCalendar::isFuture(new CalendarMonth(2026, 12), $today));
        self::assertFalse(GoalCalendar::isFuture(new CalendarMonth(2026, 1), $today));
        self::assertTrue(GoalCalendar::isFuture(new CalendarMonth(2027, 1), $today));
    }

    public function testOrdinalIncreasesByOneAcrossAYearBoundary(): void
    {
        self::assertSame(1, GoalCalendar::ordinal(new CalendarMonth(2027, 1)) - GoalCalendar::ordinal(new CalendarMonth(2026, 12)));
    }
}
