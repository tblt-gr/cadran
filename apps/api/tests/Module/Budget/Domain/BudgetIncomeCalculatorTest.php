<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain;

use App\Module\Budget\Domain\BudgetIncomeCalculator;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\MonthlyMovement;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use PHPUnit\Framework\TestCase;

final class BudgetIncomeCalculatorTest extends TestCase
{
    public function testItSumsIncomeMovementsForTheSingleWorkspaceAsset(): void
    {
        // Hand-computed: a 2500.00 salary and a 100.50 refund of an expense
        // (not income) sum to exactly 2500.00 cash income.
        $eur = AssetCode::fromString('EUR');
        $movements = [
            new MonthlyMovement(DecimalValue::fromString('2500.00'), $eur, MonthlyMovementKind::INCOME, [], false),
            new MonthlyMovement(DecimalValue::fromString('100.50'), $eur, MonthlyMovementKind::REFUND, [], false),
        ];

        $income = BudgetIncomeCalculator::sumIncome(['EUR'], $movements);

        self::assertSame('2500.00', $income->value?->toString());
        self::assertNull($income->reason);
    }

    public function testItIsNonCalculableNeverZeroWhenThereIsNoIncomeMovement(): void
    {
        $eur = AssetCode::fromString('EUR');
        $movements = [
            new MonthlyMovement(DecimalValue::fromString('-42.00'), $eur, MonthlyMovementKind::EXPENSE, [], false),
        ];

        $income = BudgetIncomeCalculator::sumIncome(['EUR'], $movements);

        self::assertNull($income->value);
        self::assertSame(MonthlyProjectionReason::ZERO_CASH_INCOME, $income->reason);
    }

    public function testItIsNonCalculableWhenThereIsNoAccountAtAll(): void
    {
        $income = BudgetIncomeCalculator::sumIncome([], []);

        self::assertNull($income->value);
        self::assertSame(MonthlyProjectionReason::NO_ACCOUNT, $income->reason);
    }

    public function testItIsNonCalculableWhenAccountsSpanMoreThanOneAsset(): void
    {
        $income = BudgetIncomeCalculator::sumIncome(['EUR', 'USD'], []);

        self::assertNull($income->value);
        self::assertSame(MonthlyProjectionReason::MIXED_ASSETS, $income->reason);
    }

    public function testItIsNonCalculableWhenIncomeMovementsSumToExactlyZero(): void
    {
        // Zero cash income is never reported as a "0.00" target base: it is
        // non-calculable, exactly like RPT-001's own savings rate.
        $eur = AssetCode::fromString('EUR');
        $movements = [
            new MonthlyMovement(DecimalValue::zero(), $eur, MonthlyMovementKind::INCOME, [], false),
        ];

        $income = BudgetIncomeCalculator::sumIncome(['EUR'], $movements);

        self::assertNull($income->value);
        self::assertSame(MonthlyProjectionReason::ZERO_CASH_INCOME, $income->reason);
    }
}
