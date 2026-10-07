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
    public ?DecimalValue $taxes;
    public ?DecimalValue $grossAmount;

    /** @param list<OperationLeg> $legs */
    public function __construct(
        public OperationType $type,
        public \DateTimeImmutable $tradeDate,
        public ?\DateTimeImmutable $settlementDate,
        public ?string $instrumentId,
        public ?string $counterInstrumentId,
        public ?DecimalValue $quantity,
        public ?DecimalValue $unitPrice,
        ?DecimalValue $grossAmount,
        public ?DecimalValue $fees,
        ?DecimalValue $taxes,
        public ?DecimalValue $costBasis,
        public ?DecimalValue $counterQuantity,
        public ?DecimalValue $ratioNumerator,
        public ?DecimalValue $ratioDenominator,
        public ?DecimalValue $costAllocationRatio,
        ?string $reference,
        ?string $note,
        public ?string $linkedTransactionId,
        public array $legs = [],
        public ?DecimalValue $withheldIncomeTax = null,
        public ?DecimalValue $withheldSocialContributions = null,
        bool $allowUnbalancedSwitch = false,
    ) {
        if ((null === $ratioNumerator) !== (null === $ratioDenominator)) {
            throw new InvalidPortfolioOperation('A ratio carries a numerator and a denominator.', 'invalid_corporate_action');
        }
        OperationFieldMatrix::assertPresence($type, [
            'legs' => [] !== $legs,
            'withheldIncomeTax' => null !== $withheldIncomeTax,
            'withheldSocialContributions' => null !== $withheldSocialContributions,
            'instrument' => null !== $instrumentId,
            'quantity' => null !== $quantity,
            'unitPrice' => null !== $unitPrice,
            'grossAmount' => OperationType::SWITCH !== $type && null !== $grossAmount,
            'fees' => null !== $fees,
            'taxes' => null !== $taxes,
            'costBasis' => null !== $costBasis,
            'counterInstrument' => null !== $counterInstrumentId,
            'counterQuantity' => null !== $counterQuantity,
            'ratio' => null !== $ratioNumerator,
            'costAllocationRatio' => null !== $costAllocationRatio,
            'linkedTransaction' => null !== $linkedTransactionId,
        ]);

        self::assertNotNegative($withheldIncomeTax);
        self::assertNotNegative($withheldSocialContributions);
        try {
            $this->taxes = $type->isSurrender() ? ExactDecimal::add($withheldIncomeTax ?? DecimalValue::zero(), $withheldSocialContributions ?? DecimalValue::zero()) : $taxes;
        } catch (\App\Module\Foundation\Domain\PrecisionExceeded|\App\Module\Foundation\Domain\MalformedDecimal) {
            throw new InvalidPortfolioOperation('The observed withholdings are too large to store exactly.', 'invalid_operation_amount');
        }
        if (count($legs) > 20) {
            throw new InvalidPortfolioOperation('At most twenty support legs are allowed.', 'too_many_operation_legs');
        }
        $seen = [];
        foreach ($legs as $offset => $leg) {
            if ($leg->index !== $offset + 1 || (OperationType::SWITCH !== $type && $leg->direction !== (OperationType::PREMIUM === $type ? LegDirection::IN : LegDirection::OUT))) {
                throw new InvalidPortfolioOperation('The support leg direction is invalid.', 'invalid_leg_direction', ['legIndex' => $leg->index]);
            }
            $key = $leg->direction->value.':'.$leg->instrumentId;
            if (isset($seen[$key])) {
                throw new InvalidPortfolioOperation('A support is repeated in the same direction.', 'duplicate_leg_instrument', ['legIndex' => $leg->index]);
            }
            $seen[$key] = true;
        }
        $this->grossAmount = OperationType::SWITCH === $type ? $this->supportsAmount(LegDirection::OUT) : $grossAmount;
        if (OperationType::SWITCH === $type) {
            foreach ([LegDirection::OUT, LegDirection::IN] as $direction) {
                $side = array_filter($legs, static fn (OperationLeg $leg): bool => $leg->direction === $direction);
                if ([] === $side) {
                    throw InvalidPortfolioOperation::field('operation_field_required', 'legs');
                }
                if (count($side) > 10) {
                    throw new InvalidPortfolioOperation('At most ten legs per side are allowed.', 'too_many_operation_legs');
                }
            }
            foreach ($legs as $leg) {
                if (isset($seen['IN:'.$leg->instrumentId], $seen['OUT:'.$leg->instrumentId])) {
                    throw new InvalidPortfolioOperation('A support cannot be sold and bought in the same switch.', 'switch_same_support');
                }
            }
            if (!$allowUnbalancedSwitch && !ExactDecimal::isZero($this->switchDifference() ?? DecimalValue::zero())) {
                throw new InvalidPortfolioOperation('The switch amounts must balance exactly.', 'switch_not_balanced', ['expectedIncomingAmount' => ($this->expectedIncomingAmount() ?? throw new \LogicException('A switch has an expected incoming amount.'))->toString()]);
            }
        }
        if ($type->isSurrender() && $this->netPaidAmount()?->isNegative()) {
            throw new InvalidPortfolioOperation('Surrender withholdings and fees exceed the gross.', 'invalid_surrender_amounts');
        }
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

    public function netPaidAmount(): ?DecimalValue
    {
        return $this->type->isSurrender() ? ExactDecimal::subtract(ExactDecimal::subtract($this->grossAmount ?? DecimalValue::zero(), $this->fees ?? DecimalValue::zero()), $this->taxes ?? DecimalValue::zero()) : null;
    }

    public function supportsAmount(?LegDirection $direction = null): DecimalValue
    {
        $sum = DecimalValue::zero();
        try {
            foreach ($this->legs as $leg) {
                if (null !== $direction && $leg->direction !== $direction) {
                    continue;
                }
                $sum = ExactDecimal::add($sum, $leg->amount);
            }
        } catch (\App\Module\Foundation\Domain\PrecisionExceeded|\App\Module\Foundation\Domain\MalformedDecimal) {
            throw new InvalidPortfolioOperation('The support total is too large to store exactly.', 'invalid_operation_amount');
        }

        return $sum;
    }

    public function expectedIncomingAmount(): ?DecimalValue
    {
        try {
            return OperationType::SWITCH === $this->type ? ExactDecimal::subtract($this->supportsAmount(LegDirection::OUT), $this->fees ?? DecimalValue::zero()) : null;
        } catch (\App\Module\Foundation\Domain\PrecisionExceeded|\App\Module\Foundation\Domain\MalformedDecimal) {
            throw new InvalidPortfolioOperation('The switch amount is too large to store exactly.', 'invalid_operation_amount');
        }
    }

    public function switchDifference(): ?DecimalValue
    {
        $expected = $this->expectedIncomingAmount();

        try {
            return null === $expected ? null : ExactDecimal::subtract($this->supportsAmount(LegDirection::IN), $expected);
        } catch (\App\Module\Foundation\Domain\PrecisionExceeded|\App\Module\Foundation\Domain\MalformedDecimal) {
            throw new InvalidPortfolioOperation('The switch difference is too large to store exactly.', 'invalid_operation_amount');
        }
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
