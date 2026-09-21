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
                self::movement('5000.00', MonthlyMovementKind::INCOME, transactionId: 'income'),
                self::movement('-1500.00', MonthlyMovementKind::EXPENSE, [
                    self::split(self::FOOD, '-1000.00'),
                    self::split(self::LEISURE, '-500.00'),
                ], transactionId: 'split-expense'),
                self::movement('-200.00', MonthlyMovementKind::EXPENSE, transactionId: 'uncategorized'),
                self::movement('100.00', MonthlyMovementKind::REFUND, [self::split(self::FOOD, '100.00')], transactionId: 'refund'),
                self::movement('750.00', MonthlyMovementKind::TRANSFER, savingsDestination: true, transactionId: 'savings'),
                self::movement('50.00', MonthlyMovementKind::ADJUSTMENT, transactionId: 'ignored'),
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
        self::assertSame(['income'], $metrics->cashIncome->sourceTransactionIds);
        self::assertSame(['refund', 'split-expense', 'uncategorized'], $metrics->budgetExpenses->sourceTransactionIds);
        self::assertSame(['uncategorized'], $metrics->uncategorizedExpenses->sourceTransactionIds);
        self::assertSame(['income', 'refund', 'split-expense', 'uncategorized'], $metrics->budgetSurplus->sourceTransactionIds);
        self::assertSame(['savings'], $metrics->savingsTransfers->sourceTransactionIds);
        self::assertSame(['income', 'refund', 'split-expense', 'uncategorized'], $metrics->cashSavingsRate->sourceTransactionIds);
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

    public function testPendingCountsFollowEachMetricScopeAndMissingMetricsKeepRelevantSources(): void
    {
        $pending = [
            self::movement('10.00', MonthlyMovementKind::INCOME, transactionId: 'pending-income'),
            self::movement('-20.00', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-20.00')], transactionId: 'pending-expense'),
            self::movement('-30.00', MonthlyMovementKind::EXPENSE, [self::split(self::LEISURE, '-30.00')], transactionId: 'pending-excluded'),
            self::movement('40.00', MonthlyMovementKind::TRANSFER, savingsDestination: true, transactionId: 'pending-savings'),
            self::movement('50.00', MonthlyMovementKind::ADJUSTMENT, transactionId: 'pending-unrelated'),
        ];
        $metrics = MonthlyProjectionCalculator::compute(
            ['EUR'],
            [self::movement('100.00', MonthlyMovementKind::INCOME, transactionId: 'booked-income')],
            [self::FOOD => true, self::LEISURE => false],
            $pending,
        );

        self::assertSame(1, $metrics->cashIncome->pendingCount);
        self::assertSame(1, $metrics->budgetExpenses->pendingCount);
        self::assertSame(0, $metrics->uncategorizedExpenses->pendingCount);
        self::assertSame(2, $metrics->budgetSurplus->pendingCount);
        self::assertSame(1, $metrics->savingsTransfers->pendingCount);
        self::assertSame(2, $metrics->cashSavingsRate->pendingCount);

        $mixed = MonthlyProjectionCalculator::compute(
            ['EUR', 'USD'],
            [
                self::movement('100.00', MonthlyMovementKind::INCOME, transactionId: 'mixed-income'),
                self::movement('-20.00', MonthlyMovementKind::EXPENSE, transactionId: 'mixed-expense'),
            ],
            [self::FOOD => true, self::LEISURE => false],
            $pending,
        );
        self::assertSame(MonthlyProjectionReason::MIXED_ASSETS, $mixed->cashIncome->reason);
        self::assertSame(['mixed-income'], $mixed->cashIncome->sourceTransactionIds);
        self::assertSame(['mixed-expense'], $mixed->budgetExpenses->sourceTransactionIds);
        self::assertSame(['mixed-expense', 'mixed-income'], $mixed->budgetSurplus->sourceTransactionIds);
        self::assertSame(1, $mixed->cashIncome->pendingCount);
        self::assertSame(1, $mixed->budgetExpenses->pendingCount);

        $noAccount = MonthlyProjectionCalculator::compute(
            [],
            [self::movement('100.00', MonthlyMovementKind::INCOME, transactionId: 'orphan-income')],
            [],
        );
        self::assertSame(MonthlyProjectionReason::NO_ACCOUNT, $noAccount->cashIncome->reason);
        self::assertSame(['orphan-income'], $noAccount->cashIncome->sourceTransactionIds);
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
        string $transactionId = '',
    ): MonthlyMovement {
        return new MonthlyMovement(
            DecimalValue::fromString($amount),
            AssetCode::fromString('EUR'),
            $kind,
            $splits,
            $savingsDestination,
            $transactionId,
        );
    }

    private static function split(string $categoryId, string $amount): MonthlySplit
    {
        return new MonthlySplit($categoryId, DecimalValue::fromString($amount));
    }
}
