<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain\Aggregation;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Reporting\Domain\Aggregation\ResponseDistribution;
use PHPUnit\Framework\TestCase;

final class ResponseDistributionTest extends TestCase
{
    public function testAnOddCountMedianIsTheMiddleValue(): void
    {
        $distribution = ResponseDistribution::of([
            self::month(2026, 1, '10'),
            self::month(2026, 2, '30'),
            self::month(2026, 3, '20'),
        ]);

        self::assertSame('20', $distribution->median);
        self::assertNull($distribution->reason);
    }

    public function testAnEvenCountMedianIsTheAverageOfTheTwoMiddleValues(): void
    {
        $distribution = ResponseDistribution::of([
            self::month(2026, 1, '10'),
            self::month(2026, 2, '20'),
            self::month(2026, 3, '30'),
            self::month(2026, 4, '40'),
        ]);

        self::assertSame('25.000000000000000000000000', $distribution->median);
    }

    public function testATieAtTheExtremesIsWonByTheEarliestMonth(): void
    {
        $distribution = ResponseDistribution::of([
            self::month(2026, 3, '100'),
            self::month(2026, 1, '100'),
            self::month(2026, 2, '50'),
        ]);

        self::assertSame('100', $distribution->maximum?->value);
        self::assertSame('2026-01', $distribution->maximum->month->key());
    }

    public function testNegativeValuesSortBelowZeroForTheMedianAndTheMinimum(): void
    {
        $distribution = ResponseDistribution::of([
            self::month(2026, 1, '-500'),
            self::month(2026, 2, '100'),
            self::month(2026, 3, '-10'),
            self::month(2026, 4, '400'),
        ]);

        self::assertSame('-500', $distribution->minimum?->value);
        self::assertSame('2026-01', $distribution->minimum->month->key());
        self::assertSame('400', $distribution->maximum?->value);
        // Sorted ascending: -500, -10, 100, 400 -> median is the mean of the two middle values.
        self::assertSame('45.000000000000000000000000', $distribution->median);
    }

    public function testAnEmptyPopulationHasNoMedianOrExtremes(): void
    {
        $distribution = ResponseDistribution::of([]);

        self::assertNull($distribution->median);
        self::assertNull($distribution->minimum);
        self::assertNull($distribution->maximum);
        self::assertSame(AggregateReason::EMPTY_POPULATION, $distribution->reason);
    }

    public function testAMonthWithoutAValueMakesTheWholeDistributionUnavailable(): void
    {
        $distribution = ResponseDistribution::of([
            self::month(2026, 1, '10'),
            self::month(2026, 2, null),
        ]);

        self::assertNull($distribution->median);
        self::assertNull($distribution->minimum);
        self::assertNull($distribution->maximum);
        self::assertSame(AggregateReason::MISSING_MONTH_VALUE, $distribution->reason);
    }

    private static function month(int $year, int $month, ?string $value): MonthValue
    {
        return new MonthValue(new CalendarMonth($year, $month), MonthState::COMPLETE, null === $value ? null : DecimalValue::fromString($value), AssetCode::fromString('EUR'));
    }
}
