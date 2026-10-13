<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use PHPUnit\Framework\TestCase;

final class LatencyStatisticsTest extends TestCase
{
    public function testNearestRankPercentilesOfTwentySamplesAreTheTenthAndNineteenthSmallest(): void
    {
        // Nearest rank: ceil(0.50 x 20) = 10th and ceil(0.95 x 20) = 19th value of the sorted samples.
        $samples = range(20, 1);
        $statistics = new LatencyStatistics(array_map(static fn (int $ms): float => (float) $ms, $samples));

        self::assertSame(10.0, $statistics->percentile(50));
        self::assertSame(19.0, $statistics->percentile(95));
        self::assertSame(20.0, $statistics->percentile(100));
    }

    public function testASingleSampleIsEveryPercentile(): void
    {
        $statistics = new LatencyStatistics([42.5]);

        self::assertSame(42.5, $statistics->percentile(50));
        self::assertSame(42.5, $statistics->percentile(95));
    }

    public function testTheRankRoundsUpSoTheP95OfTenSamplesIsTheLargest(): void
    {
        // ceil(0.95 x 10) = 10: a slow outlier is never averaged away.
        $statistics = new LatencyStatistics([5.0, 1.0, 3.0, 2.0, 4.0, 6.0, 7.0, 8.0, 9.0, 100.0]);

        self::assertSame(100.0, $statistics->percentile(95));
        self::assertSame(5.0, $statistics->percentile(50));
    }

    public function testWithoutSamplesThereIsNoPercentile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LatencyStatistics([]);
    }

    public function testAPercentileOutsideOneToHundredIsRefused(): void
    {
        $statistics = new LatencyStatistics([1.0, 2.0]);

        $this->expectException(\InvalidArgumentException::class);
        $statistics->percentile(0);
    }
}
