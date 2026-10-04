<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Application\Performance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Application\Performance\PerformanceFigureFactory;
use App\Module\Portfolio\Domain\Cash\PortfolioCostPolicy;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformanceMethod;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;
use App\Module\Portfolio\Domain\Performance\PerformanceReason;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use PHPUnit\Framework\TestCase;

final class PerformanceFigureFactoryTest extends TestCase
{
    public function testTheMethodCatalogueCarriesItsBaseQualityAndVersion(): void
    {
        self::assertSame('PERF-1', PerformanceMethod::VERSION);
        self::assertSame(
            ['EXACT', 'APPROXIMATION', 'EXACT', 'EXACT'],
            array_map(static fn (PerformanceMethod $method): string => $method->baseQuality()->value, PerformanceMethod::cases()),
        );
        self::assertSame(['TOTAL_GAIN', 'MODIFIED_DIETZ', 'TWR', 'MWR'], array_map(static fn (PerformanceMethod $method): string => $method->value, PerformanceMethod::cases()));
    }

    public function testAgeDaysCountFromTheAnchorDateToTheWorkspaceToday(): void
    {
        $start = PeriodAnchor::valuation('v1', new \DateTimeImmutable('2026-02-28'), DecimalValue::fromString('10000.00'), DecimalValue::fromString('9000.00'), 'POSITIONS', [], []);
        $end = PeriodAnchor::valuation('v2', new \DateTimeImmutable('2026-03-31'), DecimalValue::fromString('11050.00'), DecimalValue::fromString('9500.00'), 'TOTAL', ['STALE_PRICES'], []);

        $explanation = (new PerformanceFigureFactory())->explain(
            PerformanceMethod::TOTAL_GAIN, $start, $end, new \DateTimeImmutable('2026-04-10'),
            ['startValue' => '10000.00', 'endValue' => '11050.00', 'netFlows' => '800.00'],
            PerformanceQuality::EXACT, [], [], 0,
        );

        self::assertSame(['2026-02-28', 41, 'CURRENT'], [$explanation->freshness['start']['date'] ?? null, $explanation->freshness['start']['ageDays'] ?? null, $explanation->freshness['start']['priceQuality'] ?? null]);
        self::assertSame(['2026-03-31', 10, 'NOT_DETAILED'], [$explanation->freshness['end']['date'] ?? null, $explanation->freshness['end']['ageDays'] ?? null, $explanation->freshness['end']['priceQuality'] ?? null]);
        self::assertSame([31, 'PERF-1', PortfolioCostPolicy::PERFORMANCE_BASIS], [$explanation->days, $explanation->methodVersion, $explanation->costPolicy]);
    }

    public function testStalePricesAndTheOpeningAnchor(): void
    {
        $stale = PeriodAnchor::valuation('v1', new \DateTimeImmutable('2026-03-31'), DecimalValue::fromString('1.00'), DecimalValue::fromString('1.00'), 'POSITIONS', ['STALE_PRICES'], []);
        $factory = new PerformanceFigureFactory();

        $explanation = $factory->explain(PerformanceMethod::MWR, PeriodAnchor::opening(new \DateTimeImmutable('2026-01-31')), $stale, new \DateTimeImmutable('2026-03-31'), [], PerformanceQuality::DEGRADED, [new PerformanceFinding(PerformanceReason::STALE_PRICES)], [], 0);

        $opening = $explanation->freshness['start'];
        self::assertNotNull($opening);
        self::assertNull($opening['priceQuality']);
        self::assertSame(['STALE', 0], [$explanation->freshness['end']['priceQuality'] ?? null, $explanation->freshness['end']['ageDays'] ?? null]);
    }

    public function testAnAnchorAfterTodayHasAnAgeOfZeroNotANegativeOne(): void
    {
        $future = PeriodAnchor::valuation('v1', new \DateTimeImmutable('2026-05-01'), DecimalValue::fromString('1.00'), DecimalValue::fromString('1.00'), 'POSITIONS', [], []);

        $explanation = (new PerformanceFigureFactory())->explain(PerformanceMethod::TOTAL_GAIN, null, $future, new \DateTimeImmutable('2026-04-10'), [], PerformanceQuality::EXACT, [], [], 0);

        self::assertSame(0, $explanation->freshness['end']['ageDays'] ?? null);
    }

    public function testWithoutAnchorsFreshnessAndPeriodAreNull(): void
    {
        $explanation = (new PerformanceFigureFactory())->explain(PerformanceMethod::TWR, null, null, new \DateTimeImmutable('2026-03-31'), ['breakpointCount' => 0, 'subperiodCount' => 0], PerformanceQuality::NOT_CALCULABLE, [], [], 0);

        self::assertSame(['start' => null, 'end' => null], $explanation->freshness);
        self::assertSame([null, null, null], [$explanation->from, $explanation->to, $explanation->days]);
    }
}
