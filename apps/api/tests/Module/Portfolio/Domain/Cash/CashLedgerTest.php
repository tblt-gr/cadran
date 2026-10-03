<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Cash;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\Cash\CashLedgerResult;
use App\Module\Portfolio\Domain\OperationCashEffect;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CashLedgerTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string ETF = '00000000-0000-7000-8000-000000000b01';

    #[Test]
    public function itReplaysTheReferenceSequenceWithExactRunningBalancesAndTotals(): void
    {
        $result = CashLedger::replay([], $this->reference());

        self::assertSame(
            ['3000.00', '692.32', '701.07', '696.07', '694.87', '1693.28', '1193.28'],
            array_map(static fn ($movement): string => $movement->runningBalance->toString(), $result->movements),
        );
        self::assertSame('0', $result->openingBalance->toString());
        self::assertSame('1193.28', $result->closingBalance->toString());
        self::assertSame('8.98', $result->totals->fees->toString());
        self::assertSame('4.95', $result->totals->taxes->toString());
        self::assertSame('3000.00', $result->totals->deposits->toString());
        self::assertSame('500.00', $result->totals->withdrawals->toString());
        self::assertSame('2305.69', $result->totals->buysGross->toString());
        self::assertSame('1000.40', $result->totals->sellsGross->toString());
        self::assertSame('12.50', $result->totals->dividendsGross->toString());
        self::assertSame('0.002', $result->totals->roundingResiduals->toString());
        self::assertSame('1193.28', self::identity($result)->toString());
    }

    #[Test]
    public function aSellPlacedBeforeTheDividendChangesRunningBalancesButNotTheClosingBalance(): void
    {
        $operations = $this->reference();
        [$dividend, $fee, $tax, $sell] = [$operations[2], $operations[3], $operations[4], $operations[5]];
        $reordered = [$operations[0], $operations[1], $this->at($sell, 3), $this->at($dividend, 4), $fee, $tax, $operations[6]];
        $result = CashLedger::replay([], $reordered);

        self::assertSame('1690.73', $result->movements[2]->runningBalance->toString());
        self::assertSame('1193.28', $result->closingBalance->toString());
        self::assertSame('1193.28', self::identity($result)->toString());
    }

    #[Test]
    public function theOpeningBalanceIsTheCashEffectOfTheOperationsBeforeTheRange(): void
    {
        $operations = $this->reference();
        $result = CashLedger::replay(array_slice($operations, 0, 2), array_slice($operations, 2));

        self::assertSame('692.32', $result->openingBalance->toString());
        self::assertSame('1193.28', $result->closingBalance->toString());
        self::assertSame('701.07', $result->movements[0]->runningBalance->toString());
        self::assertSame('1193.28', self::identity($result)->toString());
    }

    #[Test]
    public function anEmptyLedgerIsZeroAndNotNull(): void
    {
        $result = CashLedger::replay([], []);

        self::assertSame('0', $result->openingBalance->toString());
        self::assertSame('0', $result->closingBalance->toString());
        self::assertSame([], $result->movements);
        self::assertSame('0', $result->totals->fees->toString());
    }

    #[Test]
    public function aMergerCashInLieuIsATotalOfItsOwn(): void
    {
        $result = CashLedger::replay([], [$this->operation('01', OperationType::MERGER, '2026-03-02', 1, gross: '3.20')]);

        self::assertSame('3.20', $result->totals->cashInLieu->toString());
        self::assertSame('3.20', $result->closingBalance->toString());
        self::assertSame('3.20', self::identity($result)->toString());
    }

    #[Test]
    public function itKeepsVeryLargeStoredDecimalsExact(): void
    {
        $large = '99999999999999999999999999.99';
        $result = CashLedger::replay([], [
            $this->operation('01', OperationType::DEPOSIT, '2026-03-02', 1, gross: $large),
            $this->operation('02', OperationType::FEE, '2026-03-03', 1, fees: '0.01'),
        ]);

        self::assertSame('99999999999999999999999999.98', $result->closingBalance->toString());
        self::assertSame('0.01', $result->totals->fees->toString());
    }

    #[Test]
    public function itListsTheOperationsThatTakeTheBalanceBelowZero(): void
    {
        $result = CashLedger::replay([], [
            $this->operation('01', OperationType::DEPOSIT, '2026-03-02', 1, gross: '100.00'),
            $this->operation('02', OperationType::WITHDRAWAL, '2026-03-03', 1, gross: '100.01'),
            $this->operation('03', OperationType::FEE, '2026-03-04', 1, fees: '1.00'),
            $this->operation('04', OperationType::DEPOSIT, '2026-03-05', 1, gross: '500.00'),
        ]);

        self::assertSame(['00000000-0000-7000-8000-000000000002', '00000000-0000-7000-8000-000000000003'], $result->negativeOperationIds);
        self::assertSame('2026-03-03', $result->firstNegativeDate?->format('Y-m-d'));
    }

    #[Test]
    public function aZeroBalanceIsNotNegative(): void
    {
        $result = CashLedger::replay([], [
            $this->operation('01', OperationType::DEPOSIT, '2026-03-02', 1, gross: '100.00'),
            $this->operation('02', OperationType::WITHDRAWAL, '2026-03-03', 1, gross: '100.00'),
        ]);

        self::assertSame([], $result->negativeOperationIds);
        self::assertNull($result->firstNegativeDate);
    }

    /** @return list<PortfolioOperation> */
    private function reference(): array
    {
        return [
            $this->operation('01', OperationType::DEPOSIT, '2026-03-02', 1, gross: '3000.00'),
            $this->operation('02', OperationType::BUY, '2026-03-02', 2, quantity: '10', unitPrice: '230.5688', gross: '2305.69', fees: '1.99'),
            $this->operation('03', OperationType::DIVIDEND, '2026-03-03', 1, gross: '12.50', taxes: '3.75'),
            $this->operation('04', OperationType::FEE, '2026-03-04', 1, fees: '5.00'),
            $this->operation('05', OperationType::TAX, '2026-03-05', 1, taxes: '1.20'),
            $this->operation('06', OperationType::SELL, '2026-03-06', 1, quantity: '4', unitPrice: '250.10', gross: '1000.40', fees: '1.99'),
            $this->operation('07', OperationType::WITHDRAWAL, '2026-03-07', 1, gross: '500.00'),
        ];
    }

    private function at(PortfolioOperation $operation, int $day): PortfolioOperation
    {
        $date = new \DateTimeImmutable('2026-03-0'.$day);
        $d = $operation->details;
        $details = new OperationDetails(
            $d->type, $date, null, $d->instrumentId, null, $d->quantity, $d->unitPrice, $d->grossAmount, $d->fees, $d->taxes,
            null, null, null, null, null, null, null, null,
        );

        return new PortfolioOperation(
            $operation->id, $operation->workspace, self::ACCOUNT, $operation->assetCode, $details, 1, $operation->cashAmount,
            $operation->roundingResidual, OperationState::ACTIVE, null, null, null, null, null, new \DateTimeImmutable('2026-01-01'),
            '00000000-0000-7000-8000-000000000002', 1,
        );
    }

    private function operation(
        string $suffix,
        OperationType $type,
        string $date,
        int $sequence,
        ?string $quantity = null,
        ?string $unitPrice = null,
        ?string $gross = null,
        ?string $fees = null,
        ?string $taxes = null,
    ): PortfolioOperation {
        $trade = in_array($type, [OperationType::BUY, OperationType::SELL], true);
        $details = new OperationDetails(
            $type, new \DateTimeImmutable($date), null,
            in_array($type, [OperationType::BUY, OperationType::SELL, OperationType::DIVIDEND, OperationType::MERGER], true) ? self::ETF : null,
            OperationType::MERGER === $type ? '00000000-0000-7000-8000-000000000b02' : null,
            null === $quantity ? null : DecimalValue::fromString($quantity),
            null === $unitPrice ? null : DecimalValue::fromString($unitPrice),
            null === $gross ? null : DecimalValue::fromString($gross),
            null === $fees ? ($trade ? DecimalValue::zero() : null) : DecimalValue::fromString($fees),
            null === $taxes ? ($trade ? DecimalValue::zero() : null) : DecimalValue::fromString($taxes),
            null, OperationType::MERGER === $type ? DecimalValue::fromString('1') : null, null, null, null, null, null, null,
        );
        $effect = OperationCashEffect::derive(
            $type, $details->quantity, $details->unitPrice, $details->grossAmount,
            $details->fees ?? DecimalValue::zero(), $details->taxes ?? DecimalValue::zero(), 2,
        );

        return new PortfolioOperation(
            '00000000-0000-7000-8000-0000000000'.$suffix,
            WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), self::ACCOUNT, AssetCode::fromString('EUR'),
            $details, $sequence, $effect->cashAmount, $effect->roundingResidual, OperationState::ACTIVE, null, null, null, null,
            null, new \DateTimeImmutable('2026-01-01'), '00000000-0000-7000-8000-000000000002', 1,
        );
    }

    private static function identity(CashLedgerResult $result): DecimalValue
    {
        $t = $result->totals;
        $sum = $result->openingBalance;
        foreach ([$t->deposits, $t->sellsGross, $t->dividendsGross, $t->interestGross, $t->cashInLieu] as $plus) {
            $sum = ExactDecimal::add($sum, $plus);
        }
        foreach ([$t->withdrawals, $t->buysGross, $t->fees, $t->taxes] as $minus) {
            $sum = ExactDecimal::subtract($sum, $minus);
        }

        return $sum;
    }
}
