<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * What the owner states about an operation, as exact decimals with the scale
 * they were entered in. It never carries the cash effect: the server derives it.
 */
final readonly class OperationDetails
{
    public const int MAX_SETTLEMENT_DELAY_DAYS = 31;
    public const int MAX_RATIO_SCALE = 12;

    public ?string $reference;
    public ?string $note;

    public function __construct(
        public OperationType $type,
        public \DateTimeImmutable $tradeDate,
        public ?\DateTimeImmutable $settlementDate,
        public ?string $instrumentId,
        public ?string $counterInstrumentId,
        public ?DecimalValue $quantity,
        public ?DecimalValue $unitPrice,
        public ?DecimalValue $grossAmount,
        public ?DecimalValue $fees,
        public ?DecimalValue $taxes,
        public ?DecimalValue $costBasis,
        public ?DecimalValue $counterQuantity,
        public ?DecimalValue $ratioNumerator,
        public ?DecimalValue $ratioDenominator,
        public ?DecimalValue $costAllocationRatio,
        ?string $reference,
        ?string $note,
        public ?string $linkedTransactionId,
    ) {
        if ((null === $ratioNumerator) !== (null === $ratioDenominator)) {
            throw new InvalidPortfolioOperation('A ratio carries a numerator and a denominator.', 'invalid_corporate_action');
        }
        OperationFieldMatrix::assertPresence($type, [
            'instrument' => null !== $instrumentId,
            'quantity' => null !== $quantity,
            'unitPrice' => null !== $unitPrice,
            'grossAmount' => null !== $grossAmount,
            'fees' => null !== $fees,
            'taxes' => null !== $taxes,
            'costBasis' => null !== $costBasis,
            'counterInstrument' => null !== $counterInstrumentId,
            'counterQuantity' => null !== $counterQuantity,
            'ratio' => null !== $ratioNumerator,
            'costAllocationRatio' => null !== $costAllocationRatio,
            'linkedTransaction' => null !== $linkedTransactionId,
        ]);

        self::assertPositive($quantity, 'invalid_operation_quantity');
        self::assertPositive($counterQuantity, 'invalid_operation_quantity');
        self::assertPositive($unitPrice, 'invalid_operation_amount');
        self::assertPositive($grossAmount, 'invalid_operation_amount');
        self::assertNotNegative($fees);
        self::assertNotNegative($taxes);
        self::assertNotNegative($costBasis);
        if (OperationType::FEE === $type) {
            self::assertPositive($fees, 'invalid_operation_amount');
        }
        if (OperationType::TAX === $type) {
            self::assertPositive($taxes, 'invalid_operation_amount');
        }
        $this->assertCorporateAction();
        $this->assertSettlement();

        $this->reference = OperationText::normalize($reference, OperationText::MAX_REFERENCE);
        $this->note = OperationText::normalize($note, OperationText::MAX_NOTE);
    }

    private function assertCorporateAction(): void
    {
        if (null !== $this->counterInstrumentId && $this->counterInstrumentId === $this->instrumentId) {
            throw new InvalidPortfolioOperation('The counter instrument must differ from the instrument.', 'invalid_corporate_action');
        }
        if (null !== $this->ratioNumerator && null !== $this->ratioDenominator) {
            foreach ([$this->ratioNumerator, $this->ratioDenominator] as $term) {
                if ($term->scale() > self::MAX_RATIO_SCALE || $term->isNegative() || ExactDecimal::isZero($term)) {
                    throw new InvalidPortfolioOperation('A ratio is made of two positive terms.', 'invalid_corporate_action');
                }
            }
            if (0 === $this->ratioNumerator->compareTo($this->ratioDenominator)) {
                throw new InvalidPortfolioOperation('A split ratio must change the quantity.', 'invalid_corporate_action');
            }
        }
        if (null !== $this->costAllocationRatio
            && ($this->costAllocationRatio->scale() > self::MAX_RATIO_SCALE
                || $this->costAllocationRatio->isNegative()
                || ExactDecimal::isZero($this->costAllocationRatio)
                || $this->costAllocationRatio->compareTo(DecimalValue::fromString('1')) >= 0)) {
            throw new InvalidPortfolioOperation('A cost allocation ratio lies strictly between 0 and 1.', 'invalid_corporate_action');
        }
    }

    private function assertSettlement(): void
    {
        if (null === $this->settlementDate) {
            return;
        }
        $limit = $this->tradeDate->modify('+'.self::MAX_SETTLEMENT_DELAY_DAYS.' days');
        if ($this->settlementDate < $this->tradeDate || $this->settlementDate > $limit) {
            throw new InvalidPortfolioOperation('A settlement date follows the trade date by at most 31 days.', 'invalid_settlement_date');
        }
    }

    private static function assertPositive(?DecimalValue $value, string $code): void
    {
        if (null !== $value && ($value->isNegative() || ExactDecimal::isZero($value))) {
            throw new InvalidPortfolioOperation('This figure must be strictly positive.', $code);
        }
    }

    private static function assertNotNegative(?DecimalValue $value): void
    {
        if (null !== $value && $value->isNegative()) {
            throw new InvalidPortfolioOperation('This amount cannot be negative.', 'invalid_operation_amount');
        }
    }
}
