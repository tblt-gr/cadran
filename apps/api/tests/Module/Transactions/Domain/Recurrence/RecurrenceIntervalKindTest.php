<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\Recurrence\RecurrenceIntervalKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurrenceIntervalKindTest extends TestCase
{
    /** @return iterable<string, array{string, ?RecurrenceIntervalKind}> */
    public static function medianGaps(): iterable
    {
        yield 'zero days names no interval' => ['0', null];
        yield 'five days is below the weekly floor' => ['5', null];
        yield 'six days is the weekly floor' => ['6', RecurrenceIntervalKind::WEEKLY];
        yield 'eight days is the weekly ceiling' => ['8', RecurrenceIntervalKind::WEEKLY];
        yield 'eight days and a half falls between intervals' => ['8.5', null];
        yield 'twenty-six days is below the monthly floor' => ['26', null];
        yield 'twenty-seven days is the monthly floor' => ['27', RecurrenceIntervalKind::MONTHLY];
        yield 'a half-day monthly median stays monthly' => ['30.5', RecurrenceIntervalKind::MONTHLY];
        yield 'thirty-two days is the monthly ceiling' => ['32', RecurrenceIntervalKind::MONTHLY];
        yield 'thirty-two days and a half falls between intervals' => ['32.5', null];
        yield 'eighty-eight days is the quarterly floor' => ['88', RecurrenceIntervalKind::QUARTERLY];
        yield 'ninety-five days is the quarterly ceiling' => ['95', RecurrenceIntervalKind::QUARTERLY];
        yield 'ninety-five days and a half falls between intervals' => ['95.5', null];
        yield 'three hundred and sixty days is the yearly floor' => ['360', RecurrenceIntervalKind::YEARLY];
        yield 'three hundred and seventy days is the yearly ceiling' => ['370', RecurrenceIntervalKind::YEARLY];
        yield 'three hundred and seventy-one days names no interval' => ['371', null];
    }

    #[DataProvider('medianGaps')]
    public function testAMedianGapIsClassifiedOnItsInclusiveBounds(string $medianGapDays, ?RecurrenceIntervalKind $expected): void
    {
        self::assertSame($expected, RecurrenceIntervalKind::classify(DecimalValue::fromString($medianGapDays)));
    }

    public function testAWeeklyScheduleAnchorsOnAnIsoWeekday(): void
    {
        self::assertTrue(RecurrenceIntervalKind::WEEKLY->accepts(1));
        self::assertTrue(RecurrenceIntervalKind::WEEKLY->accepts(7));
        self::assertFalse(RecurrenceIntervalKind::WEEKLY->accepts(0));
        self::assertFalse(RecurrenceIntervalKind::WEEKLY->accepts(8));
    }

    public function testAnyOtherScheduleAnchorsOnADayOfMonth(): void
    {
        foreach ([RecurrenceIntervalKind::MONTHLY, RecurrenceIntervalKind::QUARTERLY, RecurrenceIntervalKind::YEARLY] as $kind) {
            self::assertTrue($kind->accepts(1));
            self::assertTrue($kind->accepts(31));
            self::assertFalse($kind->accepts(0));
            self::assertFalse($kind->accepts(32));
        }
    }
}
