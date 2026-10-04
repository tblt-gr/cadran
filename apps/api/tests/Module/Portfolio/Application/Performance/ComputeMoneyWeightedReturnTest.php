<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Application\Performance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Application\Performance\ComputeMoneyWeightedReturn;
use App\Module\Portfolio\Application\Performance\PerformancePeriodData;
use App\Module\Portfolio\Domain\Performance\MoneyWeightedReturnCalculator;
use App\Module\Portfolio\Domain\Performance\MoneyWeightedReturnSolver;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;
use App\Module\Portfolio\Domain\Performance\PerformanceReason;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use PHPUnit\Framework\TestCase;

final class ComputeMoneyWeightedReturnTest extends TestCase
{
    public function testGainBlockingAndWarningsAreInherited(): void
    {
        $compute = new ComputeMoneyWeightedReturn(new MoneyWeightedReturnCalculator(new MoneyWeightedReturnSolver()));
        $anchor = static fn (string $date, string $value): PeriodAnchor => PeriodAnchor::valuation('id', new \DateTimeImmutable($date), DecimalValue::fromString($value), DecimalValue::zero(), 'TOTAL', [], []);
        $data = new PerformancePeriodData($anchor('2025-12-31', '1000'), $anchor('2026-12-31', '1100'), [], [], [], [], 2);
        $exact = $compute($data, [], []);
        self::assertSame(PerformanceQuality::EXACT, $exact->quality);
        self::assertSame('0.100000', $exact->annualizedRate);
        $warning = new PerformanceFinding(PerformanceReason::PERIOD_IN_PROGRESS);
        $degraded = $compute($data, [], [$warning]);
        self::assertSame(PerformanceQuality::DEGRADED, $degraded->quality);
        self::assertSame([$warning], $degraded->findings);
        $block = new PerformanceFinding(PerformanceReason::UNVALUED_SECURITY_TRANSFER);
        $blocked = $compute($data, [$block], [$warning]);
        self::assertNull($blocked->annualizedRate);
        self::assertSame(PerformanceQuality::NOT_CALCULABLE, $blocked->quality);
        self::assertSame([$block, $warning], $blocked->findings);
    }
}
