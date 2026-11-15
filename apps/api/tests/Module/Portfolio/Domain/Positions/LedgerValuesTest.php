<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\Positions\LedgerValues;
use App\Module\Portfolio\Domain\Positions\OpenLot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LedgerValuesTest extends TestCase
{
    #[Test]
    public function lotCostSumsKnownCostsAndIsZeroWithoutLots(): void
    {
        self::assertSame('0', LedgerValues::lotCost([])?->toString());
        self::assertSame('30.5', LedgerValues::lotCost([$this->lot('1', '10.5'), $this->lot('2', '20')])?->toString());
    }

    #[Test]
    public function lotCostIsNullWhenAnyLotCostIsUnknown(): void
    {
        self::assertNull(LedgerValues::lotCost([$this->lot('1', '10'), $this->lot('2', null)]));
    }

    #[Test]
    public function acquisitionCostAddsFeesAndTaxesExactly(): void
    {
        $cost = LedgerValues::acquisitionCost(DecimalValue::fromString('100.10'), DecimalValue::fromString('0.20'), DecimalValue::fromString('0.05'));

        self::assertSame('100.35', $cost->toString());
    }

    #[Test]
    public function acquisitionCostTreatsMissingFeesAndTaxesAsZero(): void
    {
        self::assertSame('100', LedgerValues::acquisitionCost(DecimalValue::fromString('100'), null, null)->toString());
    }

    #[Test]
    public function acquisitionCostRequiresTheGrossAmount(): void
    {
        $this->expectException(\LogicException::class);
        LedgerValues::acquisitionCost(null, null, null);
    }

    #[Test]
    public function calculatedAndNullableCalculatedKeepTheValueAndTheNull(): void
    {
        self::assertSame('1.50', LedgerValues::calculated(DecimalValue::fromString('1.50'))->toString());
        self::assertSame('1.50', LedgerValues::nullableCalculated(DecimalValue::fromString('1.50'))?->toString());
        self::assertNull(LedgerValues::nullableCalculated(null));
    }

    #[Test]
    public function derivedQuantityDropsInsignificantTrailingZeros(): void
    {
        self::assertSame('2.5', LedgerValues::derivedQuantity(DecimalValue::fromString('2.500000'))->toString());
        self::assertSame('3', LedgerValues::derivedQuantity(DecimalValue::fromString('3.000'))->toString());
        self::assertSame('100', LedgerValues::derivedQuantity(DecimalValue::fromString('100'))->toString());
        self::assertSame('0', LedgerValues::derivedQuantity(DecimalValue::fromString('0.00'))->toString());
    }

    #[Test]
    public function requiredReturnsTheValue(): void
    {
        self::assertSame('abc', LedgerValues::required('abc'));
        self::assertSame('1', LedgerValues::requiredDecimal(DecimalValue::fromString('1'))->toString());
    }

    #[Test]
    public function requiredFailsOnNull(): void
    {
        $this->expectException(\LogicException::class);
        LedgerValues::required(null);
    }

    #[Test]
    public function requiredDecimalFailsOnNull(): void
    {
        $this->expectException(\LogicException::class);
        LedgerValues::requiredDecimal(null);
    }

    private function lot(string $id, ?string $cost): OpenLot
    {
        $quantity = CalculatedDecimal::fromDecimal(DecimalValue::fromString('1'));

        return new OpenLot(
            $id, $id, OperationType::BUY, new \DateTimeImmutable('2026-01-01'), 1, $quantity, $quantity,
            null === $cost ? null : CalculatedDecimal::fromDecimal(DecimalValue::fromString($cost)),
        );
    }
}
