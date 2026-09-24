<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Reporting\Domain\MonthlyRecapWindow;
use PHPUnit\Framework\TestCase;

final class MonthlyRecapWindowTest extends TestCase
{
    public function testAClosedMonthComparesTheLastDayOfThePrecedingMonthWithItsOwnCalendarEnd(): void
    {
        $window = MonthlyRecapWindow::of(new CalendarMonth(2026, 9), self::day('2026-10-05'));

        self::assertSame('2026-08-31', $window->previousAsOf->format('Y-m-d'));
        self::assertSame('2026-09-30', $window->currentAsOf->format('Y-m-d'));
        self::assertFalse($window->provisional);
    }

    public function testThePrecedingMonthEndIsNeverTheFirstDayOfTheSelectedMonth(): void
    {
        $window = MonthlyRecapWindow::of(new CalendarMonth(2026, 9), self::day('2026-10-05'));

        self::assertNotSame('2026-09-01', $window->previousAsOf->format('Y-m-d'));
    }

    public function testAnUnfinishedCurrentMonthStopsOnTheWorkspaceLocalTodayAndIsProvisional(): void
    {
        $window = MonthlyRecapWindow::of(new CalendarMonth(2026, 9), self::day('2026-09-24'));

        self::assertSame('2026-08-31', $window->previousAsOf->format('Y-m-d'));
        self::assertSame('2026-09-24', $window->currentAsOf->format('Y-m-d'));
        self::assertTrue($window->provisional);
    }

    public function testTheFirstDayOfAMonthStillComparesAgainstThePrecedingMonthEnd(): void
    {
        $window = MonthlyRecapWindow::of(new CalendarMonth(2026, 9), self::day('2026-09-01'));

        self::assertSame('2026-08-31', $window->previousAsOf->format('Y-m-d'));
        self::assertSame('2026-09-01', $window->currentAsOf->format('Y-m-d'));
        self::assertTrue($window->provisional);
    }

    public function testTheLastDayOfAMonthCompletesItRatherThanLeavingItProvisional(): void
    {
        $window = MonthlyRecapWindow::of(new CalendarMonth(2026, 9), self::day('2026-09-30'));

        self::assertSame('2026-09-30', $window->currentAsOf->format('Y-m-d'));
        self::assertFalse($window->provisional);
    }

    public function testJanuaryComparesAgainstTheLastDayOfThePreviousYear(): void
    {
        $window = MonthlyRecapWindow::of(new CalendarMonth(2026, 1), self::day('2026-02-10'));

        self::assertSame('2025-12-31', $window->previousAsOf->format('Y-m-d'));
        self::assertSame('2026-01-31', $window->currentAsOf->format('Y-m-d'));
    }

    public function testMarchComparesAgainstTheShorterFebruaryItFollows(): void
    {
        $window = MonthlyRecapWindow::of(new CalendarMonth(2026, 3), self::day('2026-04-02'));

        self::assertSame('2026-02-28', $window->previousAsOf->format('Y-m-d'));

        $leap = MonthlyRecapWindow::of(new CalendarMonth(2028, 3), self::day('2028-04-02'));
        self::assertSame('2028-02-29', $leap->previousAsOf->format('Y-m-d'));
    }

    public function testAMonthThatHasNotStartedKeepsItsCalendarEndAndNeverInvertsTheComparison(): void
    {
        $window = MonthlyRecapWindow::of(new CalendarMonth(2027, 1), self::day('2026-09-24'));

        self::assertSame('2026-12-31', $window->previousAsOf->format('Y-m-d'));
        self::assertSame('2027-01-31', $window->currentAsOf->format('Y-m-d'));
        self::assertFalse($window->provisional);
    }

    public function testTheComparedDayAlwaysPrecedesTheEffectiveDay(): void
    {
        foreach (['2025-12-31', '2026-09-01', '2026-09-15', '2026-09-30', '2030-01-01'] as $today) {
            $window = MonthlyRecapWindow::of(new CalendarMonth(2026, 9), self::day($today));
            self::assertLessThan($window->currentAsOf, $window->previousAsOf, 'today='.$today);
        }
    }

    private static function day(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
