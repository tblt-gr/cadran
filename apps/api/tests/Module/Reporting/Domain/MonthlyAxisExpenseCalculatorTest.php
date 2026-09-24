<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\MonthlyAxisExpenseCalculator;
use App\Module\Reporting\Domain\MonthlyMovement;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Reporting\Domain\MonthlySplit;
use PHPUnit\Framework\TestCase;

final class MonthlyAxisExpenseCalculatorTest extends TestCase
{
    private const string FOOD = '00000000-0000-7000-8000-0000000000c1';
    private const string LEISURE = '00000000-0000-7000-8000-0000000000c2';
    private const array AXES = ['DISCRETIONARY', 'ESSENTIAL', 'FIXED', 'PERSONAL', 'PROFESSIONAL', 'VARIABLE'];

    /**
     * Hand-computed month, all amounts EUR:
     *   rent      EXPENSE  -1000.00  FOOD    axes ESSENTIAL, FIXED
     *   snacks    EXPENSE   -200.00  FOOD    axes DISCRETIONARY
     *   fee       FEE        -50.00  FOOD    axes ESSENTIAL
     *   cinema    EXPENSE   -300.00  LEISURE axes ESSENTIAL   (category outside the budget)
     *   refund    REFUND    +100.00  FOOD    axes DISCRETIONARY
     * ESSENTIAL    = -(-1000.00 - 50.00)    = 1050.00
     * FIXED        = -(-1000.00)            = 1000.00
     * DISCRETIONARY= -(-200.00 + 100.00)    =  100.00
     * The others carry no split, so they are an exact zero, not an absence.
     */
    public function testTheHandComputedAxisBreakdownIsExact(): void
    {
        $axes = MonthlyAxisExpenseCalculator::compute(['EUR'], self::month(), [self::FOOD => true, self::LEISURE => false], self::AXES);

        self::assertSame('1050.00', $axes['ESSENTIAL']->value?->toString());
        self::assertSame('1000.00', $axes['FIXED']->value?->toString());
        self::assertSame('100.00', $axes['DISCRETIONARY']->value?->toString());
        self::assertSame('0', $axes['PERSONAL']->value?->toString());
        self::assertSame('0', $axes['PROFESSIONAL']->value?->toString());
        self::assertSame('0', $axes['VARIABLE']->value?->toString());
        self::assertSame('EUR', $axes['ESSENTIAL']->asset?->toString());
    }

    public function testEveryRequestedAxisIsPublishedExactlyOnce(): void
    {
        $axes = MonthlyAxisExpenseCalculator::compute(['EUR'], self::month(), [self::FOOD => true], self::AXES);

        self::assertSame(self::AXES, array_keys($axes));
    }

    public function testAnAxisNamesTheTransactionsBehindIt(): void
    {
        $axes = MonthlyAxisExpenseCalculator::compute(['EUR'], self::month(), [self::FOOD => true, self::LEISURE => false], self::AXES);

        self::assertSame(['fee', 'rent'], $axes['ESSENTIAL']->sourceTransactionIds);
        self::assertSame(['refund', 'snacks'], $axes['DISCRETIONARY']->sourceTransactionIds);
        self::assertSame([], $axes['VARIABLE']->sourceTransactionIds);
    }

    public function testAnUnsplitExpenseIsAttributedToNoAxisRatherThanToAllOfThem(): void
    {
        $axes = MonthlyAxisExpenseCalculator::compute(
            ['EUR'],
            [self::movement('-400.00', MonthlyMovementKind::EXPENSE, [], 'unsplit')],
            [],
            self::AXES,
        );

        foreach (self::AXES as $axis) {
            self::assertSame('0', $axes[$axis]->value?->toString());
        }
    }

    public function testVeryLargeDecimalsStayExact(): void
    {
        $axes = MonthlyAxisExpenseCalculator::compute(
            ['EUR'],
            [
                self::movement('-123456789012345678901234.123456789012345678901234', MonthlyMovementKind::EXPENSE, [
                    new MonthlySplit(self::FOOD, DecimalValue::fromString('-123456789012345678901234.123456789012345678901234'), ['FIXED']),
                ], 'huge'),
                self::movement('-0.000000000000000000000001', MonthlyMovementKind::EXPENSE, [
                    new MonthlySplit(self::FOOD, DecimalValue::fromString('-0.000000000000000000000001'), ['FIXED']),
                ], 'dust'),
            ],
            [self::FOOD => true],
            self::AXES,
        );

        self::assertSame('123456789012345678901234.123456789012345678901235', $axes['FIXED']->value?->toString());
    }

    public function testMixedAssetsLeaveEveryAxisNonCalculableRatherThanZero(): void
    {
        $axes = MonthlyAxisExpenseCalculator::compute(['EUR', 'USD'], self::month(), [self::FOOD => true], self::AXES);

        foreach (self::AXES as $axis) {
            self::assertNull($axes[$axis]->value);
            self::assertSame(MonthlyProjectionReason::MIXED_ASSETS, $axes[$axis]->reason);
        }
    }

    public function testAWorkspaceWithoutAnAccountHasNoAxisTotalAtAll(): void
    {
        $axes = MonthlyAxisExpenseCalculator::compute([], [], [], self::AXES);

        foreach (self::AXES as $axis) {
            self::assertNull($axes[$axis]->value);
            self::assertSame(MonthlyProjectionReason::NO_ACCOUNT, $axes[$axis]->reason);
        }
    }

    /** @return list<MonthlyMovement> */
    private static function month(): array
    {
        return [
            self::movement('-1000.00', MonthlyMovementKind::EXPENSE, [
                new MonthlySplit(self::FOOD, DecimalValue::fromString('-1000.00'), ['ESSENTIAL', 'FIXED']),
            ], 'rent'),
            self::movement('-200.00', MonthlyMovementKind::EXPENSE, [
                new MonthlySplit(self::FOOD, DecimalValue::fromString('-200.00'), ['DISCRETIONARY']),
            ], 'snacks'),
            self::movement('-50.00', MonthlyMovementKind::FEE, [
                new MonthlySplit(self::FOOD, DecimalValue::fromString('-50.00'), ['ESSENTIAL']),
            ], 'fee'),
            self::movement('-300.00', MonthlyMovementKind::EXPENSE, [
                new MonthlySplit(self::LEISURE, DecimalValue::fromString('-300.00'), ['ESSENTIAL']),
            ], 'cinema'),
            self::movement('100.00', MonthlyMovementKind::REFUND, [
                new MonthlySplit(self::FOOD, DecimalValue::fromString('100.00'), ['DISCRETIONARY']),
            ], 'refund'),
            self::movement('5000.00', MonthlyMovementKind::INCOME, [], 'salary'),
            self::movement('750.00', MonthlyMovementKind::TRANSFER, [], 'savings'),
        ];
    }

    /** @param list<MonthlySplit> $splits */
    private static function movement(string $amount, MonthlyMovementKind $kind, array $splits, string $transactionId): MonthlyMovement
    {
        return new MonthlyMovement(
            DecimalValue::fromString($amount),
            AssetCode::fromString('EUR'),
            $kind,
            $splits,
            false,
            $transactionId,
        );
    }
}
