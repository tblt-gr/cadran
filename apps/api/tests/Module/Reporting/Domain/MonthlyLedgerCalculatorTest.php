<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\MonthlyLedgerCalculator;
use App\Module\Reporting\Domain\MonthlyLedgerEntry;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlySavingsTransfer;
use App\Module\Reporting\Domain\MonthlySplit;
use PHPUnit\Framework\TestCase;

final class MonthlyLedgerCalculatorTest extends TestCase
{
    public function testSplitRefundAndBookedAxisAreAttributedExactlyOnce(): void
    {
        $expense = self::entry('01', MonthlyMovementKind::EXPENSE, [
            new MonthlySplit('food', DecimalValue::fromString('-60'), ['ESSENTIAL']),
            new MonthlySplit('transport', DecimalValue::fromString('-40'), ['VARIABLE']),
        ]);
        $refund = self::entry('02', MonthlyMovementKind::REFUND, [
            new MonthlySplit('food', DecimalValue::fromString('20'), ['ESSENTIAL']),
        ]);

        $sources = MonthlyLedgerCalculator::categorySources([$expense, $refund], 'EXPENSE', 'food', 'ESSENTIAL');
        $total = MonthlyLedgerCalculator::total($sources, invert: true);

        self::assertSame('40', $total->value?->toString());
        self::assertSame('EUR', $total->asset?->toString());
        self::assertNull($total->reason);
        self::assertSame(2, $total->movementCount);
        self::assertSame(['20', '-60'], array_map(static fn ($source): string => $source->amount->toString(), $sources));
        self::assertSame([], MonthlyLedgerCalculator::categorySources([$expense, $refund], 'EXPENSE', 'food', 'VARIABLE'));
    }

    /**
     * Hand-computed roll-up: parent 10.00 of its own, child A 5.50, child B 2.25 and a sibling
     * category 4.00 that belongs to no one of them. The parent subtree is 10.00 + 5.50 + 2.25 = 17.75.
     */
    public function testACategorySubtreeIsTheSumOfItsOwnAndItsDescendantsMovements(): void
    {
        $entries = [
            self::entry('01', MonthlyMovementKind::INCOME, [new MonthlySplit('parent', DecimalValue::fromString('10.00'), [])]),
            self::entry('02', MonthlyMovementKind::INCOME, [new MonthlySplit('child-a', DecimalValue::fromString('5.50'), [])]),
            self::entry('03', MonthlyMovementKind::INCOME, [new MonthlySplit('child-b', DecimalValue::fromString('2.25'), [])]),
            self::entry('04', MonthlyMovementKind::INCOME, [new MonthlySplit('other', DecimalValue::fromString('4.00'), [])]),
        ];

        $subtree = MonthlyLedgerCalculator::categorySources($entries, 'INCOME', ['parent', 'child-a', 'child-b'], null);
        $total = MonthlyLedgerCalculator::total($subtree, invert: false);

        self::assertSame('17.75', $total->value?->toString());
        self::assertSame(3, $total->movementCount);
        // A single id keeps meaning that category alone.
        self::assertSame('10.00', MonthlyLedgerCalculator::total(MonthlyLedgerCalculator::categorySources($entries, 'INCOME', 'parent', null), invert: false)->value?->toString());
    }

    public function testASubtreeCountsATransactionSplitAcrossTheParentAndAChildOnce(): void
    {
        $split = self::entry('01', MonthlyMovementKind::EXPENSE, [
            new MonthlySplit('parent', DecimalValue::fromString('-6.00'), []),
            new MonthlySplit('child-a', DecimalValue::fromString('-4.00'), []),
        ]);

        $sources = MonthlyLedgerCalculator::categorySources([$split], 'EXPENSE', ['parent', 'child-a'], null);

        self::assertCount(1, $sources);
        self::assertSame('10.00', MonthlyLedgerCalculator::total($sources, invert: true)->value?->toString());
    }

    public function testASubtreeWithoutAnyMovementStaysNotCalculableAndNeverZero(): void
    {
        $entries = [self::entry('01', MonthlyMovementKind::INCOME, [new MonthlySplit('other', DecimalValue::fromString('4.00'), [])])];

        $total = MonthlyLedgerCalculator::total(MonthlyLedgerCalculator::categorySources($entries, 'INCOME', ['parent', 'child-a'], null), invert: false);

        self::assertNull($total->value);
        self::assertSame('NO_MOVEMENTS', $total->reason);
    }

    public function testEmptyActualZeroAndMixedAssetsRemainDistinct(): void
    {
        $empty = MonthlyLedgerCalculator::total([], invert: false);
        self::assertNull($empty->value);
        self::assertSame('NO_MOVEMENTS', $empty->reason);
        self::assertFalse($empty->hasMovements);

        $zeroSources = MonthlyLedgerCalculator::categorySources([
            self::entry('01', MonthlyMovementKind::INCOME, [new MonthlySplit('salary', DecimalValue::fromString('10'), [])]),
            self::entry('02', MonthlyMovementKind::INCOME, [new MonthlySplit('salary', DecimalValue::fromString('-10'), [])]),
        ], 'INCOME', 'salary', null);
        $zero = MonthlyLedgerCalculator::total($zeroSources, invert: false);
        self::assertSame('0', $zero->value?->toString());
        self::assertTrue($zero->hasMovements);

        $mixed = MonthlyLedgerCalculator::total([
            $zeroSources[0],
            MonthlyLedgerCalculator::categorySources([
                self::entry('03', MonthlyMovementKind::INCOME, [new MonthlySplit('salary', DecimalValue::fromString('1'), [])], 'USD'),
            ], 'INCOME', 'salary', null)[0],
        ], invert: false);
        self::assertNull($mixed->value);
        self::assertSame('MIXED_ASSETS', $mixed->reason);
        self::assertTrue($mixed->hasMovements);
    }

    public function testOnlyExactBookedOppositeTransferLegsContribute(): void
    {
        $pair = new MonthlySavingsTransfer(
            '00000000-0000-7000-8000-0000000004a3',
            '00000000-0000-7000-8000-0000000001a1',
            '00000000-0000-7000-8000-0000000001a2',
            '00000000-0000-7000-8000-0000000000a1',
            '00000000-0000-7000-8000-0000000000a2',
            DecimalValue::fromString('-300.00'),
            DecimalValue::fromString('300.00'),
            AssetCode::fromString('EUR'),
            AssetCode::fromString('EUR'),
            'BOOKED',
            'BOOKED',
            '2026-09-15',
            '2026-09-15',
            false,
        );

        self::assertTrue(MonthlyLedgerCalculator::validTransfer($pair));
        $pending = new MonthlySavingsTransfer(
            $pair->transferId,
            $pair->sourceTransactionId,
            $pair->targetTransactionId,
            $pair->sourceAccountId,
            $pair->targetAccountId,
            $pair->sourceAmount,
            $pair->targetAmount,
            $pair->sourceAsset,
            $pair->targetAsset,
            'PENDING',
            'PENDING',
            $pair->sourceBookedOn,
            $pair->targetBookedOn,
            false,
        );
        self::assertTrue(MonthlyLedgerCalculator::wellFormedTransfer($pending));
        self::assertFalse(MonthlyLedgerCalculator::validTransfer($pending));
        self::assertFalse(MonthlyLedgerCalculator::validTransfer(new MonthlySavingsTransfer(
            $pair->transferId,
            $pair->sourceTransactionId,
            $pair->targetTransactionId,
            $pair->sourceAccountId,
            $pair->targetAccountId,
            DecimalValue::fromString('300.00'),
            DecimalValue::fromString('-300.00'),
            $pair->sourceAsset,
            $pair->targetAsset,
            $pair->sourceState,
            $pair->targetState,
            $pair->sourceBookedOn,
            $pair->targetBookedOn,
            false,
        )));
    }

    /** @param list<MonthlySplit> $splits */
    private static function entry(
        string $suffix,
        MonthlyMovementKind $kind,
        array $splits,
        string $asset = 'EUR',
    ): MonthlyLedgerEntry {
        return new MonthlyLedgerEntry(
            '00000000-0000-7000-8000-0000000001'.$suffix,
            AssetCode::fromString($asset),
            $kind,
            new \DateTimeImmutable('2026-09-15'),
            'Movement '.$suffix,
            $splits,
        );
    }
}
