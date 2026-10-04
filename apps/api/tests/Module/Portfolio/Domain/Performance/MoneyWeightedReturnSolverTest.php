<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Performance\MoneyWeightedReturnSolver;
use App\Module\Portfolio\Domain\Performance\PerformanceReason;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyWeightedReturnSolverTest extends TestCase
{
    #[Test]
    public function referenceRatesAndOrdering(): void
    {
        foreach ([
            [[[0, '-1000'], [365, '1100']], 365, '0.100000', '0.100000'],
            [[[0, '-10000'], [182, '-1000'], [365, '11800']], 365, '0.076247', '0.076247'],
            [[[0, '-1000'], [60, '1010']], 60, '0.062401', '0.010000'],
            [[[0, '-1000'], [1, '0'], [365, '1000']], 365, '0.000000', '0.000000'],
        ] as [$flows, $days, $annual, $period]) {
            foreach ([$flows, array_reverse($flows)] as $ordered) {
                $result = (new MoneyWeightedReturnSolver())->solve(self::flows($ordered), $days);
                self::assertNull($result->reason);
                self::assertSame($annual, $result->annualizedRate);
                self::assertSame($period, $result->periodRate);
            }
        }
    }

    #[Test]
    public function exactRangeEndpointsAndLargeAmounts(): void
    {
        foreach ([['0.98', '-0.999373', '-0.020000'], ['1.01', '36.783434', '0.010000']] as [$terminal, $annual, $period]) {
            $result = (new MoneyWeightedReturnSolver())->solve(self::flows([[0, '-1'], [1, $terminal]]), 1);
            self::assertSame($annual, $result->annualizedRate);
            self::assertSame($period, $result->periodRate);
            self::assertSame(0, $result->iterations);
        }
        $large = (new MoneyWeightedReturnSolver())->solve(self::flows([[0, '-10000000000000000000000000'], [365, '11000000000000000000000000']]), 365);
        self::assertSame('0.100000', $large->annualizedRate);
        $net = (new MoneyWeightedReturnSolver())->solve(self::flows([[0, '-2000'], [0, '1000'], [365, '1100']]), 365);
        self::assertSame('0.100000', $net->annualizedRate);
    }

    #[Test]
    public function zeroTerminalAnchorsDoNotManufactureRootsByUnderflow(): void
    {
        foreach ([[3650, '1.593742'], [7300, '5.727500'], [18250, '116.390853']] as [$days, $period]) {
            $result = (new MoneyWeightedReturnSolver())->solve(self::flows([[0, '-1000'], [365, '1100'], [$days, '0']]), $days);
            self::assertNull($result->reason);
            self::assertSame('0.100000', $result->annualizedRate);
            self::assertSame($period, $result->periodRate);
        }
    }

    #[Test]
    public function narrowingOnTheLastAllowedIterationStillConverges(): void
    {
        $result = (new MoneyWeightedReturnSolver(16))->solve(self::flows([[0, '-1000'], [365, '1100']]), 365);
        self::assertNull($result->reason);
        self::assertSame(16, $result->iterations);
        self::assertSame('0.100000', $result->annualizedRate);
    }

    #[Test]
    public function failuresAreStable(): void
    {
        foreach ([
            [[[0, '-1000'], [365, '2300'], [730, '-1320']], 730, PerformanceReason::MULTIPLE_ROOTS],
            [[[0, '-1'], [365, '1000']], 365, PerformanceReason::NO_ROOT_IN_RANGE],
            [[[0, '-1'], [365, '-2']], 365, PerformanceReason::NO_SIGN_CHANGE],
            [[[0, '1'], [365, '2']], 365, PerformanceReason::NO_SIGN_CHANGE],
            [[[0, '0'], [365, '0']], 365, PerformanceReason::NO_SIGN_CHANGE],
        ] as [$flows, $days, $reason]) {
            $result = (new MoneyWeightedReturnSolver())->solve(self::flows($flows), $days);
            self::assertSame($reason, $result->reason);
            self::assertNull($result->annualizedRate);
        }
        self::assertSame(PerformanceReason::NO_CONVERGENCE, (new MoneyWeightedReturnSolver(1))->solve(self::flows([[0, '-1000'], [365, '1100']]), 365)->reason);
    }

    #[Test]
    public function cumulativeSignsProveAWithdrawalRootUnique(): void
    {
        $result = (new MoneyWeightedReturnSolver())->solve(self::flows([[0, '-1000'], [100, '500'], [200, '-100'], [365, '660']]), 365);
        self::assertNull($result->reason);
        self::assertTrue($result->uniqueRoot);
    }

    #[Test]
    #[Group('performance')]
    public function fiveHundredDaysOverTenYearsStayBounded(): void
    {
        $flows = [[0, '-1000']];
        for ($i = 1; $i < 499; ++$i) {
            $flows[] = [$i * 7, '-1'];
        }
        $flows[] = [3650, '2000'];
        $started = hrtime(true);
        self::assertNull((new MoneyWeightedReturnSolver())->solve(self::flows($flows), 3650)->reason);
        self::assertLessThan(2_000_000_000, hrtime(true) - $started);
        $flows[] = [3649, '-1'];
        self::assertSame(PerformanceReason::TOO_MANY_FLOWS, (new MoneyWeightedReturnSolver())->solve(self::flows($flows), 3650)->reason);
    }

    /** @param list<array{int, string}> $flows
     * @return list<array{day: int, amount: DecimalValue}>
     */
    private static function flows(array $flows): array
    {
        return array_map(static fn (array $flow): array => ['day' => $flow[0], 'amount' => DecimalValue::fromString($flow[1])], $flows);
    }
}
