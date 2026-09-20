<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain;

use App\Module\Budget\Domain\BudgetComparisonCalculator;
use App\Module\Budget\Domain\BudgetComparisonReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BudgetComparisonCalculatorTest extends TestCase
{
    #[DataProvider('calculableComparisons')]
    public function testItComputesAnExactVarianceAndStatus(
        string $actual,
        string $target,
        string $variance,
        string $status,
    ): void {
        $comparison = BudgetComparisonCalculator::compare($actual, $target, null);

        self::assertSame($actual, $comparison->actual);
        self::assertSame($target, $comparison->target);
        self::assertSame($variance, $comparison->variance);
        self::assertSame($status, $comparison->status);
        self::assertNull($comparison->reason);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function calculableComparisons(): iterable
    {
        yield 'within target' => ['80.25', '100.00', '19.75', 'WITHIN_TARGET'];
        yield 'exactly on target despite scale' => ['100.0', '100.00', '0.00', 'ON_TARGET'];
        yield 'over target' => ['120.125', '100.00', '-20.125', 'OVER_TARGET'];
        yield 'large exact values' => [
            '99999999999999999999999999.999999999999999999999999',
            '99999999999999999999999999.999999999999999999999999',
            '0.000000000000000000000000',
            'ON_TARGET',
        ];
    }

    public function testANonCalculableInputNeverDefaultsToZero(): void
    {
        $comparison = BudgetComparisonCalculator::compare(null, '100.00', BudgetComparisonReason::MIXED_ASSETS);

        self::assertNull($comparison->actual);
        self::assertSame('100.00', $comparison->target);
        self::assertNull($comparison->variance);
        self::assertSame('NON_CALCULABLE', $comparison->status);
        self::assertSame(BudgetComparisonReason::MIXED_ASSETS, $comparison->reason);
    }
}
