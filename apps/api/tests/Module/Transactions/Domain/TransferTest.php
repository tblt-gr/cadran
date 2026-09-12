<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\InvalidTransfer;
use App\Module\Transactions\Domain\Transfer;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class TransferTest extends TestCase
{
    private const string TRANSFER_ID = '00000000-0000-7000-8000-0000000000f1';
    private const string SOURCE_ID = '00000000-0000-7000-8000-0000000000f2';
    private const string TARGET_ID = '00000000-0000-7000-8000-0000000000f3';

    public function testASameAssetPairThatNetsToExactlyZeroIsAccepted(): void
    {
        Transfer::assertOpposition($this->eur('-500.00'), $this->eur('500.00'));
        $this->addToAssertionCount(1);
    }

    public function testASameAssetPairThatDoesNotNetToZeroIsRefused(): void
    {
        $this->expectException(InvalidTransfer::class);
        Transfer::assertOpposition($this->eur('-500.00'), $this->eur('500.01'));
    }

    public function testASameAssetPairOffByOneSmallestUnitIsRefused(): void
    {
        $this->expectException(InvalidTransfer::class);
        Transfer::assertOpposition($this->eur('-500.00'), $this->eur('499.99'));
    }

    public function testACrossAssetPairIsExemptFromOpposition(): void
    {
        Transfer::assertOpposition($this->eur('-500.00'), $this->chf('540.25'));
        $this->addToAssertionCount(1);
    }

    public function testTheExchangeRateIsTheQuotientOfTheTwoExactMagnitudesRoundedHalfUpAtScale24(): void
    {
        $rate = Transfer::deriveExchangeRate($this->eur('-500.00'), $this->chf('540.25'));

        self::assertSame('1.080500000000000000000000', $rate->toString());
    }

    public function testAQuotientWithNoFiniteDecimalRepresentationIsRoundedHalfUpAtScale24(): void
    {
        $rate = Transfer::deriveExchangeRate($this->eur('-3.00'), $this->chf('1.00'));

        self::assertSame('0.333333333333333333333333', $rate->toString());
    }

    /**
     * 2⁻²⁵ falls exactly on a tie at the 25th decimal digit: HALF_UP rounds
     * the kept digit away from zero (…313), which DOWN or a truncation would
     * not (…312). This is the one case in the suite that actually tells
     * HALF_UP apart from a simpler rounding rule.
     */
    public function testATieAtTheTwentyFifthDigitRoundsAwayFromZero(): void
    {
        $rate = Transfer::deriveExchangeRate($this->eur('-33554432.00'), $this->chf('1.00'));

        self::assertSame('0.000000029802322387695313', $rate->toString());
    }

    public function testATransferConnectingATransactionToItselfIsRefused(): void
    {
        $this->expectException(InvalidTransfer::class);
        $this->transfer(self::SOURCE_ID, self::SOURCE_ID);
    }

    public function testAFeeRepeatingALegIsRefused(): void
    {
        $this->expectException(InvalidTransfer::class);
        $this->transfer(self::SOURCE_ID, self::TARGET_ID, feeTransactionId: self::SOURCE_ID);
    }

    public function testANonPositiveExchangeRateIsRefused(): void
    {
        $this->expectException(InvalidTransfer::class);
        $this->transfer(self::SOURCE_ID, self::TARGET_ID, exchangeRate: DecimalValue::zero());
    }

    public function testEditingBumpsTheVersionAndClearsTheVoidingTimestamp(): void
    {
        $edited = $this->transfer(self::SOURCE_ID, self::TARGET_ID)
            ->edit(null, DecimalValue::fromString('1.5'), new \DateTimeImmutable('2026-03-16T10:00:00+00:00'));

        self::assertSame(2, $edited->version);
        self::assertNull($edited->voidedAt);
        self::assertSame('1.5', $edited->exchangeRate?->toString());
    }

    public function testAVoidedTransferCannotBeEditedOrVoidedAgain(): void
    {
        $voided = $this->transfer(self::SOURCE_ID, self::TARGET_ID)
            ->void(new \DateTimeImmutable('2026-03-16T10:00:00+00:00'));

        $this->expectException(InvalidTransfer::class);
        $voided->void(new \DateTimeImmutable('2026-03-17T10:00:00+00:00'));
    }

    private function transfer(
        string $sourceTransactionId,
        string $targetTransactionId,
        ?string $feeTransactionId = null,
        ?DecimalValue $exchangeRate = null,
    ): Transfer {
        return new Transfer(
            id: self::TRANSFER_ID,
            workspace: WorkspaceFixture::own(),
            sourceTransactionId: $sourceTransactionId,
            targetTransactionId: $targetTransactionId,
            feeTransactionId: $feeTransactionId,
            exchangeRate: $exchangeRate,
            version: 1,
            createdAt: new \DateTimeImmutable('2026-03-14T10:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-03-14T10:00:00+00:00'),
            voidedAt: null,
        );
    }

    private function eur(string $value): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($value), AssetCode::fromString('EUR'));
    }

    private function chf(string $value): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($value), AssetCode::fromString('CHF'));
    }
}
