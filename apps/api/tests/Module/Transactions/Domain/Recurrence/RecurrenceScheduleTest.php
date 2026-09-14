<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Recurrence;

use App\Module\Transactions\Domain\Recurrence\RecurrenceIntervalKind;
use App\Module\Transactions\Domain\Recurrence\RecurrenceSchedule;
use PHPUnit\Framework\TestCase;

final class RecurrenceScheduleTest extends TestCase
{
    public function testAMonthlyScheduleStartsOnTheRequestedDayOfTheStartingMonth(): void
    {
        $schedule = RecurrenceSchedule::anchoredOn(RecurrenceIntervalKind::MONTHLY, 4, self::day('2026-03-01'));

        self::assertSame('2026-03-04', $schedule->first()->format('Y-m-d'));
    }

    public function testAMonthlyScheduleSkipsToTheNextMonthWhenTheRequestedDayHasPassed(): void
    {
        $schedule = RecurrenceSchedule::anchoredOn(RecurrenceIntervalKind::MONTHLY, 4, self::day('2026-03-05'));

        self::assertSame('2026-04-04', $schedule->first()->format('Y-m-d'));
    }

    public function testADayThirtyOneScheduleClampsShortMonthsAndReturnsToTheThirtyFirst(): void
    {
        $schedule = RecurrenceSchedule::anchoredOn(RecurrenceIntervalKind::MONTHLY, 31, self::day('2026-01-01'));

        self::assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            self::format(array_slice($schedule->through(self::day('2026-05-31')), 0, 5)),
        );
    }

    public function testAMonthlyScheduleGeneratesTwelveDatesOverATwelveMonthHorizon(): void
    {
        $schedule = RecurrenceSchedule::anchoredOn(RecurrenceIntervalKind::MONTHLY, 15, self::day('2026-03-01'));

        $dates = $schedule->through(RecurrenceSchedule::horizon(self::day('2026-03-01')));

        self::assertCount(12, $dates);
        self::assertSame('2026-03-15', $dates[0]->format('Y-m-d'));
        self::assertSame('2027-02-15', $dates[11]->format('Y-m-d'));
    }

    public function testAWeeklyScheduleAnchorsOnTheIsoWeekdayAndStepsBySevenDays(): void
    {
        // 2026-03-02 is a Monday; ISO weekday 3 is the Wednesday that follows.
        $schedule = RecurrenceSchedule::anchoredOn(RecurrenceIntervalKind::WEEKLY, 3, self::day('2026-03-02'));

        self::assertSame(
            ['2026-03-04', '2026-03-11', '2026-03-18'],
            self::format($schedule->through(self::day('2026-03-18'))),
        );
    }

    public function testAQuarterlyScheduleStepsByThreeMonthsAndAYearlyOneByTwelve(): void
    {
        $quarterly = RecurrenceSchedule::anchoredOn(RecurrenceIntervalKind::QUARTERLY, 10, self::day('2026-02-01'));
        $yearly = RecurrenceSchedule::anchoredOn(RecurrenceIntervalKind::YEARLY, 10, self::day('2026-02-01'));

        self::assertSame(['2026-02-10', '2026-05-10', '2026-08-10'], self::format($quarterly->through(self::day('2026-08-10'))));
        self::assertSame(['2026-02-10', '2027-02-10'], self::format($yearly->through(self::day('2027-02-10'))));
    }

    public function testTheDateAfterAClampedOccurrenceReturnsToTheRequestedAnchor(): void
    {
        $schedule = RecurrenceSchedule::anchoredOn(RecurrenceIntervalKind::MONTHLY, 31, self::day('2026-01-01'));

        self::assertSame('2026-03-31', $schedule->after(self::day('2026-02-28'))->format('Y-m-d'));
        self::assertSame('2026-02-28', $schedule->after(self::day('2026-01-31'))->format('Y-m-d'));
    }

    public function testTheDateAfterAWeeklyOccurrenceIsSevenDaysLater(): void
    {
        $schedule = RecurrenceSchedule::anchoredOn(RecurrenceIntervalKind::WEEKLY, 3, self::day('2026-03-02'));

        self::assertSame('2026-03-11', $schedule->after(self::day('2026-03-04'))->format('Y-m-d'));
    }

    public function testTheHorizonIsTwelveMonthsAfterTheGivenDay(): void
    {
        self::assertSame('2027-03-01', RecurrenceSchedule::horizon(self::day('2026-03-01'))->format('Y-m-d'));
    }

    private static function day(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     *
     * @return list<string>
     */
    private static function format(array $dates): array
    {
        return array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates);
    }
}
