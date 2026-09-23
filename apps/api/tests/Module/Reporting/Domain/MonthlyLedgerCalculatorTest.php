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
        $expense = self::entry('01', MonthlyMovementKind::EXPENSE, '-100', [
            new MonthlySplit('food', DecimalValue::fromString('-60'), ['ESSENTIAL']),
            new MonthlySplit('transport', DecimalValue::fromString('-40'), ['VARIABLE']),
        ]);
        $refund = self::entry('02', MonthlyMovementKind::REFUND, '20', [
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

    public function testEmptyActualZeroAndMixedAssetsRemainDistinct(): void
    {
        $empty = MonthlyLedgerCalculator::total([], invert: false);
        self::assertNull($empty->value);
        self::assertSame('NO_MOVEMENTS', $empty->reason);
        self::assertFalse($empty->hasMovements);

        $zeroSources = MonthlyLedgerCalculator::categorySources([
            self::entry('01', MonthlyMovementKind::INCOME, '10', [new MonthlySplit('salary', DecimalValue::fromString('10'), [])]),
            self::entry('02', MonthlyMovementKind::INCOME, '-10', [new MonthlySplit('salary', DecimalValue::fromString('-10'), [])]),
        ], 'INCOME', 'salary', null);
        $zero = MonthlyLedgerCalculator::total($zeroSources, invert: false);
        self::assertSame('0', $zero->value?->toString());
        self::assertTrue($zero->hasMovements);

        $mixed = MonthlyLedgerCalculator::total([
            $zeroSources[0],
            MonthlyLedgerCalculator::categorySources([
                self::entry('03', MonthlyMovementKind::INCOME, '1', [new MonthlySplit('salary', DecimalValue::fromString('1'), [])], 'USD'),
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
        string $amount,
        array $splits,
        string $asset = 'EUR',
    ): MonthlyLedgerEntry {
        return new MonthlyLedgerEntry(
            '00000000-0000-7000-8000-0000000001'.$suffix,
            '00000000-0000-7000-8000-0000000000a1',
            DecimalValue::fromString($amount),
            AssetCode::fromString($asset),
            $kind,
            new \DateTimeImmutable('2026-09-15'),
            'Movement '.$suffix,
            $splits,
        );
    }
}
