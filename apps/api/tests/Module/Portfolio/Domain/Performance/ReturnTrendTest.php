<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Portfolio\Domain\Performance\ReturnTrend;
use App\Module\Portfolio\Domain\Performance\ReturnTrendReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Window 2025-10 .. 2026-09, as read on 2026-10-31.
 *
 * Example 1: opened and first operation on 2026-07-01, monthly rates 0.01, -0.02, 0.03:
 * 100 x 1.01 = 101.00 ; x 0.98 = 98.98 ; x 1.03 = 101.9494 -> 101.95, cumulative 1.019494 - 1 = 0.019494.
 */
final class ReturnTrendTest extends TestCase
{
    private const array WINDOW = [
        '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03',
        '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09',
    ];

    #[Test]
    public function exampleOneChainsFromTheOpeningMonthAndKeepsTheUnroundedCumulativeReturn(): void
    {
        $trend = ReturnTrend::compute(self::WINDOW, $this->rates(9, ['0.01', '-0.02', '0.03']), 9);

        self::assertSame(array_fill(0, 9, null), array_slice($this->indexes($trend), 0, 9));
        self::assertSame(['101.00', '98.98', '101.95'], array_slice($this->indexes($trend), 9));
        self::assertSame('2026-07', $trend->from);
        self::assertSame('2026-09', $trend->to);
        self::assertSame('0.019494', $trend->cumulativeReturn);
        self::assertNull($trend->reason);
        self::assertNull($trend->reasonMonth);
        self::assertSame(['month' => '2025-10', 'index' => null], $trend->months[0]);
    }

    #[Test]
    public function exampleTwoStartsInTheMonthOfTheFirstOperation(): void
    {
        // 1.01 ^ 7 = 1.07213535210701 over 2026-03 .. 2026-09.
        $trend = ReturnTrend::compute(self::WINDOW, $this->rates(5, array_fill(0, 7, '0.01')), 5);

        self::assertSame(array_fill(0, 5, null), array_slice($this->indexes($trend), 0, 5));
        self::assertSame('101.00', $this->indexes($trend)[5]);
        self::assertSame('107.21', $this->indexes($trend)[11]);
        self::assertSame('2026-03', $trend->from);
        self::assertSame('0.072135', $trend->cumulativeReturn);
    }

    #[Test]
    public function exampleThreeBreaksAtAMonthWithoutRateAndNeverResumes(): void
    {
        $trend = ReturnTrend::compute(self::WINDOW, $this->rates(9, ['0.01', null, '0.02']), 9);

        self::assertSame(['101.00', null, null], array_slice($this->indexes($trend), 9));
        self::assertSame('2026-07', $trend->from);
        self::assertNull($trend->cumulativeReturn);
        self::assertSame(ReturnTrendReason::MISSING_MONTH, $trend->reason);
        self::assertSame('2026-08', $trend->reasonMonth);
    }

    #[Test]
    public function aTotalLossBreaksTheChainWithItsOwnReason(): void
    {
        $trend = ReturnTrend::compute(self::WINDOW, $this->rates(9, ['0.01', '-1', '0.02']), 9);

        self::assertSame(['101.00', null, null], array_slice($this->indexes($trend), 9));
        self::assertNull($trend->cumulativeReturn);
        self::assertSame(ReturnTrendReason::NON_POSITIVE_GROWTH_FACTOR, $trend->reason);
        self::assertSame('2026-08', $trend->reasonMonth);
    }

    #[Test]
    public function aGrowthFactorBelowZeroBreaksTheChainToo(): void
    {
        $trend = ReturnTrend::compute(self::WINDOW, $this->rates(10, ['-1.5', '0.02']), 10);

        self::assertNull($trend->from);
        self::assertSame(ReturnTrendReason::NON_POSITIVE_GROWTH_FACTOR, $trend->reason);
        self::assertSame('2026-08', $trend->reasonMonth);
    }

    #[Test]
    public function aMissingFirstMonthChainsNothing(): void
    {
        $trend = ReturnTrend::compute(self::WINDOW, $this->rates(10, [null, '0.02']), 10);

        self::assertNull($trend->from);
        self::assertNull($trend->cumulativeReturn);
        self::assertSame(ReturnTrendReason::MISSING_MONTH, $trend->reason);
        self::assertSame('2026-08', $trend->reasonMonth);
    }

    #[Test]
    public function anUnavailableTrendKeepsTheWindowAndNamesItsReason(): void
    {
        $trend = ReturnTrend::unavailable(self::WINDOW, ReturnTrendReason::TOO_MANY_OPERATIONS);

        self::assertSame(array_fill(0, 12, null), $this->indexes($trend));
        self::assertSame('2025-10', $trend->months[0]['month']);
        self::assertSame('2026-09', $trend->to);
        self::assertNull($trend->from);
        self::assertNull($trend->cumulativeReturn);
        self::assertSame(ReturnTrendReason::TOO_MANY_OPERATIONS, $trend->reason);
        self::assertNull($trend->reasonMonth);
    }

    #[Test]
    public function aStartAfterTheWindowIsNoHistory(): void
    {
        $trend = ReturnTrend::compute(self::WINDOW, array_fill(0, 12, null), 12);

        self::assertSame(array_fill(0, 12, null), $this->indexes($trend));
        self::assertNull($trend->from);
        self::assertNull($trend->cumulativeReturn);
        self::assertSame(ReturnTrendReason::NO_HISTORY, $trend->reason);
        self::assertNull($trend->reasonMonth);
    }

    #[Test]
    public function aStartBeforeTheWindowChainsTheWholeWindow(): void
    {
        // 1.01 ^ 12 = 1.12682503013197 .
        $trend = ReturnTrend::compute(self::WINDOW, array_fill(0, 12, '0.01'), -4);

        self::assertSame('2025-10', $trend->from);
        self::assertSame('0.126825', $trend->cumulativeReturn);
        self::assertSame('112.68', $this->indexes($trend)[11]);
    }

    #[Test]
    public function aFlatWindowIsAZeroReturnNotAnUnknownOne(): void
    {
        $trend = ReturnTrend::compute(self::WINDOW, array_fill(0, 12, '0'), 0);

        self::assertSame('0.000000', $trend->cumulativeReturn);
        self::assertNull($trend->reason);
    }

    #[Test]
    public function offsettingMonthsReturnExactlyToZeroWithoutFloatingPointNoise(): void
    {
        // 1.5 x 0.5 x 1.5 x 0.5 ... = 0.5625 ^ 6 would drift in binary floats; here 1.5 x (2/3 is not decimal) so use 1 and -0.5 and 1.
        $rates = array_merge(['1', '-0.5'], array_fill(0, 10, '0'));
        $trend = ReturnTrend::compute(self::WINDOW, $rates, 0);

        self::assertSame('0.000000', $trend->cumulativeReturn);
        self::assertSame('100.00', $this->indexes($trend)[11]);
    }

    #[Test]
    public function aVeryLargeRateKeepsAnExactIndex(): void
    {
        $trend = ReturnTrend::compute(self::WINDOW, array_merge(['999999999999'], array_fill(0, 11, '0')), 0);

        self::assertSame('100000000000000.00', $this->indexes($trend)[11]);
        self::assertSame('999999999999.000000', $trend->cumulativeReturn);
    }

    #[Test]
    public function theWindowIsTheTwelveMonthsBeforeTheCurrentMonthOnItsLastDay(): void
    {
        self::assertSame(self::WINDOW, ReturnTrend::windowBefore(new \DateTimeImmutable('2026-10-31')));
    }

    #[Test]
    public function theWindowIsTheSameOnTheFirstDayOfTheMonth(): void
    {
        self::assertSame(self::WINDOW, ReturnTrend::windowBefore(new \DateTimeImmutable('2026-10-01')));
    }

    #[Test]
    public function theWindowCrossesTheYearBoundary(): void
    {
        $window = ReturnTrend::windowBefore(new \DateTimeImmutable('2026-01-31'));

        self::assertSame('2025-01', $window[0]);
        self::assertSame('2025-12', $window[11]);
        self::assertCount(12, $window);
    }

    #[Test]
    public function aRateListThatDoesNotMatchTheWindowIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReturnTrend::compute(self::WINDOW, ['0.01'], 0);
    }

    /**
     * @param list<?string> $values
     *
     * @return list<?string>
     */
    private function rates(int $start, array $values): array
    {
        return array_merge(array_fill(0, $start, null), $values);
    }

    /** @return list<?string> */
    private function indexes(ReturnTrend $trend): array
    {
        return array_column($trend->months, 'index');
    }
}
