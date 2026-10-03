<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\OperationCashEffect;
use App\Module\Portfolio\Domain\OperationType;
use PHPUnit\Framework\TestCase;

final class OperationCashEffectTest extends TestCase
{
    public function testABuyKeepsTheRoundingResidualAndTheExactCashEffect(): void
    {
        // 10 x 230.5688 = 2305.688 ; gross 2305.69 -> residual 0.002 ; cash -(2305.69 + 1.99) = -2307.68.
        $effect = OperationCashEffect::derive(OperationType::BUY, self::d('10'), self::d('230.5688'), self::d('2305.69'), self::d('1.99'), self::d('0'), 2);

        self::assertSame('-2307.68', $effect->cashAmount->toString());
        self::assertSame('0.002', $effect->roundingResidual?->toString());
        self::assertSame('2305.688', $effect->expectedGrossAmount?->toString());
    }

    public function testABuyBeyondHalfADisplayUnitIsRefusedWithTheExpectedGross(): void
    {
        try {
            OperationCashEffect::derive(OperationType::BUY, self::d('10'), self::d('230.5688'), self::d('2305.70'), self::d('1.99'), self::d('0'), 2);
            self::fail('A residual of 0.012 exceeds the 0.005 tolerance.');
        } catch (InvalidPortfolioOperation $exception) {
            self::assertSame('operation_amount_mismatch', $exception->problemCode);
            self::assertSame('2305.688', $exception->extensions['expectedGrossAmount'] ?? null);
        }
    }

    public function testTheToleranceIsInclusiveAtHalfADisplayUnit(): void
    {
        // 1 x 10.005 = 10.005 ; gross 10.01 -> residual 0.005 exactly.
        $effect = OperationCashEffect::derive(OperationType::BUY, self::d('1'), self::d('10.005'), self::d('10.01'), self::d('0'), self::d('0'), 2);
        self::assertSame('0.005', $effect->roundingResidual?->toString());
        $this->expectException(InvalidPortfolioOperation::class);
        OperationCashEffect::derive(OperationType::BUY, self::d('1'), self::d('10.005'), self::d('10.02'), self::d('0'), self::d('0'), 2);
    }

    public function testAnExactSellSubtractsFeesAndTaxes(): void
    {
        $effect = OperationCashEffect::derive(OperationType::SELL, self::d('4'), self::d('250.10'), self::d('1000.40'), self::d('1.99'), self::d('0'), 2);

        self::assertSame('998.41', $effect->cashAmount->toString());
        self::assertSame('0', $effect->roundingResidual?->toString());
    }

    public function testADividendNetsItsTaxes(): void
    {
        $effect = OperationCashEffect::derive(OperationType::DIVIDEND, null, null, self::d('12.50'), self::d('0'), self::d('3.75'), 2);

        self::assertSame('8.75', $effect->cashAmount->toString());
        self::assertNull($effect->roundingResidual);
    }

    public function testEveryTypeDerivesItsOwnSignedCashEffect(): void
    {
        $zero = self::d('0');
        self::assertSame('100.00', OperationCashEffect::derive(OperationType::DEPOSIT, null, null, self::d('100.00'), $zero, $zero, 2)->cashAmount->toString());
        self::assertSame('-100.00', OperationCashEffect::derive(OperationType::WITHDRAWAL, null, null, self::d('100.00'), $zero, $zero, 2)->cashAmount->toString());
        self::assertSame('5.00', OperationCashEffect::derive(OperationType::INTEREST, null, null, self::d('6.00'), self::d('1.00'), $zero, 2)->cashAmount->toString());
        self::assertSame('-1.50', OperationCashEffect::derive(OperationType::FEE, null, null, null, self::d('1.50'), $zero, 2)->cashAmount->toString());
        self::assertSame('-2.25', OperationCashEffect::derive(OperationType::TAX, null, null, null, $zero, self::d('2.25'), 2)->cashAmount->toString());
        self::assertSame('7.00', OperationCashEffect::derive(OperationType::MERGER, null, null, self::d('7.00'), $zero, $zero, 2)->cashAmount->toString());
        foreach ([OperationType::SECURITY_TRANSFER_IN, OperationType::SECURITY_TRANSFER_OUT, OperationType::SPLIT, OperationType::SPIN_OFF, OperationType::MERGER] as $type) {
            $cash = OperationCashEffect::derive($type, null, null, null, $zero, $zero, 2)->cashAmount;
            self::assertSame('0', $cash->toString());
        }
    }

    public function testACashEffectTooLargeToStoreIsRefusedNotWrapped(): void
    {
        $this->expectException(InvalidPortfolioOperation::class);
        $this->expectExceptionMessage('too large');
        $big = self::d(str_repeat('9', 26));
        OperationCashEffect::derive(OperationType::BUY, self::d('1'), $big, $big, self::d('1'), self::d('0'), 2);
    }

    private static function d(string $literal): DecimalValue
    {
        return DecimalValue::fromString($literal);
    }
}
