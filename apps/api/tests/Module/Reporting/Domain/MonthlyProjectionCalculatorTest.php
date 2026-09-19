<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\MonthlyMovement;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlyProjectionCalculator;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Reporting\Domain\MonthlySplit;
use PHPUnit\Framework\TestCase;

final class MonthlyProjectionCalculatorTest extends TestCase
{
    private const string FOOD = '00000000-0000-7000-8000-0000000000c1';
    private const string LEISURE = '00000000-0000-7000-8000-0000000000c2';

    public function testTheHandComputedMonthlyPolicyIsExact(): void
    {
        $metrics = MonthlyProjectionCalculator::compute(
            ['EUR'],
            [
                self::movement('5000.00', MonthlyMovementKind::INCOME),
                self::movement('-1500.00', MonthlyMovementKind::EXPENSE, [
                    self::split(self::FOOD, '-1000.00'),
                    self::split(self::LEISURE, '-500.00'),
                ]),
                self::movement('-200.00', MonthlyMovementKind::EXPENSE),
                self::movement('100.00', MonthlyMovementKind::REFUND, [self::split(self::FOOD, '100.00')]),
                self::movement('750.00', MonthlyMovementKind::TRANSFER, savingsDestination: true),
                self::movement('50.00', MonthlyMovementKind::ADJUSTMENT),
            ],
            [self::FOOD => true, self::LEISURE => false],
        );

        self::assertSame('5000.00', $metrics->cashIncome->value?->toString());
        self::assertSame('1100.00', $metrics->budgetExpenses->value?->toString());
        self::assertSame('200.00', $metrics->uncategorizedExpenses->value?->toString());
        self::assertSame('3900.00', $metrics->budgetSurplus->value?->toString());
        self::assertSame('750.00', $metrics->savingsTransfers->value?->toString());
        self::assertSame('0.780000000000000000000000', $metrics->cashSavingsRate->value?->toString());
        self::assertNull($metrics->cashSavingsRate->reason);
    }

    public function testMixedAssetsRefuseEveryAffectedTotal(): void
    {
        $metrics = MonthlyProjectionCalculator::compute(
            ['EUR', 'USD'],
            [self::movement('10.00', MonthlyMovementKind::INCOME)],
            [],
        );

        foreach ([$metrics->cashIncome, $metrics->budgetExpenses, $metrics->uncategorizedExpenses,
            $metrics->budgetSurplus, $metrics->savingsTransfers, $metrics->cashSavingsRate] as $metric) {
            self::assertNull($metric->value);
            self::assertSame(MonthlyProjectionReason::MIXED_ASSETS, $metric->reason);
        }
    }

    public function testAZeroIncomeKeepsAmountsAndMakesOnlyTheRateNonCalculable(): void
    {
        $metrics = MonthlyProjectionCalculator::compute(
            ['EUR'],
            [self::movement('-12.345678901234567890123456', MonthlyMovementKind::EXPENSE)],
            [],
        );

        self::assertSame('0', $metrics->cashIncome->value?->toString());
        self::assertSame('12.345678901234567890123456', $metrics->budgetExpenses->value?->toString());
        self::assertSame('-12.345678901234567890123456', $metrics->budgetSurplus->value?->toString());
        self::assertNull($metrics->cashSavingsRate->value);
        self::assertSame(MonthlyProjectionReason::ZERO_CASH_INCOME, $metrics->cashSavingsRate->reason);
    }

    /** @param list<MonthlySplit> $splits */
    private static function movement(
        string $amount,
        MonthlyMovementKind $kind,
        array $splits = [],
        bool $savingsDestination = false,
    ): MonthlyMovement {
        return new MonthlyMovement(
            DecimalValue::fromString($amount),
            AssetCode::fromString('EUR'),
            $kind,
            $splits,
            $savingsDestination,
        );
    }

    private static function split(string $categoryId, string $amount): MonthlySplit
    {
        return new MonthlySplit($categoryId, DecimalValue::fromString($amount));
    }
}
