<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;

/**
 * The signed cash effect of an operation and, for a buy or a sell, the gap
 * between the stated gross amount and quantity x unit price. Everything is
 * computed exactly: nothing here is ever rounded.
 */
final readonly class OperationCashEffect
{
    private function __construct(
        public DecimalValue $cashAmount,
        public ?DecimalValue $roundingResidual,
        public ?DecimalValue $expectedGrossAmount,
    ) {
    }

    /** @param list<OperationLeg> $legs */
    public static function derive(
        OperationType $type,
        ?DecimalValue $quantity,
        ?DecimalValue $unitPrice,
        ?DecimalValue $grossAmount,
        DecimalValue $fees,
        DecimalValue $taxes,
        int $displayPrecision,
        array $legs = [],
        bool $fromSupport = false,
    ): self {
        try {
            return self::compute($type, $quantity, $unitPrice, $grossAmount, $fees, $taxes, $displayPrecision, $legs, $fromSupport);
        } catch (PrecisionExceeded|MalformedDecimal $exception) {
            throw new InvalidPortfolioOperation('The operation amounts are too large to be stored exactly.', 'invalid_operation_amount');
        }
    }

    /** @param list<OperationLeg> $legs */
    private static function compute(
        OperationType $type,
        ?DecimalValue $quantity,
        ?DecimalValue $unitPrice,
        ?DecimalValue $grossAmount,
        DecimalValue $fees,
        DecimalValue $taxes,
        int $displayPrecision,
        array $legs = [],
        bool $fromSupport = false,
    ): self {
        $gross = $grossAmount ?? DecimalValue::zero();
        $net = ExactDecimal::subtract(ExactDecimal::subtract($gross, $fees), $taxes);

        return match ($type) {
            OperationType::EURO_FUND_INTEREST, OperationType::PROFIT_SHARING, OperationType::SOCIAL_CONTRIBUTION => new self(DecimalValue::zero(), null, null),
            OperationType::MANAGEMENT_FEE => self::managementFee($quantity, $unitPrice, $fees, $displayPrecision, $fromSupport),
            OperationType::SWITCH => new self(DecimalValue::zero(), null, null),
            OperationType::PREMIUM => self::premium($gross, $fees, $legs),
            OperationType::PARTIAL_SURRENDER, OperationType::FULL_SURRENDER => new self(ExactDecimal::subtract(self::supports($legs), $gross), null, null),
            OperationType::DEPOSIT => new self($gross, null, null),
            OperationType::WITHDRAWAL => new self(ExactDecimal::negate($gross), null, null),
            OperationType::BUY, OperationType::SELL => self::trade($type, $quantity, $unitPrice, $gross, $fees, $taxes, $net, $displayPrecision),
            OperationType::DIVIDEND, OperationType::INTEREST => new self($net, null, null),
            OperationType::FEE => new self(ExactDecimal::negate($fees), null, null),
            OperationType::TAX => new self(ExactDecimal::negate($taxes), null, null),
            OperationType::MERGER => new self($gross, null, null),
            OperationType::SECURITY_TRANSFER_IN, OperationType::SECURITY_TRANSFER_OUT,
            OperationType::SPLIT, OperationType::SPIN_OFF => new self(DecimalValue::zero(), null, null),
        };
    }

    /** @param list<OperationLeg> $legs */
    private static function supports(array $legs): DecimalValue
    {
        $total = DecimalValue::zero();
        foreach ($legs as $leg) {
            $total = ExactDecimal::add($total, $leg->amount);
        }

        return $total;
    }

    /** @param list<OperationLeg> $legs */
    private static function premium(DecimalValue $gross, DecimalValue $fees, array $legs): self
    {
        $cash = ExactDecimal::subtract(ExactDecimal::subtract($gross, $fees), self::supports($legs));
        if ($cash->isNegative()) {
            throw new InvalidPortfolioOperation('The support allocation exceeds the net premium.', 'premium_allocation_exceeds_net');
        }

        return new self($cash, null, null);
    }

    private static function trade(
        OperationType $type,
        ?DecimalValue $quantity,
        ?DecimalValue $unitPrice,
        DecimalValue $gross,
        DecimalValue $fees,
        DecimalValue $taxes,
        DecimalValue $net,
        int $displayPrecision,
    ): self {
        if (null === $quantity || null === $unitPrice) {
            // A trade without both factors has no expected gross: the matrix refuses it earlier, and only a
            // direct caller can reach here.
            throw new InvalidPortfolioOperation('A trade needs a quantity and a unit price.', 'operation_field_required');
        }
        $expected = ExactDecimal::multiply($quantity, $unitPrice);
        $residual = ExactDecimal::subtract($gross, $expected);
        $tolerance = DecimalValue::fromString('0.'.str_repeat('0', max(0, $displayPrecision)).'5');
        if (ExactDecimal::absolute($residual)->compareTo($tolerance) > 0) {
            throw new InvalidPortfolioOperation('The gross amount does not match quantity x unit price.', 'operation_amount_mismatch', ['expectedGrossAmount' => self::minimal($expected)->toString()]);
        }
        $cash = OperationType::BUY === $type
            ? ExactDecimal::negate(ExactDecimal::add(ExactDecimal::add($gross, $fees), $taxes))
            : $net;

        return new self($cash, self::minimal($residual), self::minimal($expected));
    }

    private static function managementFee(?DecimalValue $quantity, ?DecimalValue $price, DecimalValue $fees, int $display, bool $fromSupport): self
    {
        if (null !== $quantity && null !== $price) {
            self::trade(OperationType::SELL, $quantity, $price, $fees, DecimalValue::zero(), DecimalValue::zero(), $fees, $display);
        }

        return new self($fromSupport ? DecimalValue::zero() : ExactDecimal::negate($fees), null, null);
    }

    private static function minimal(DecimalValue $value): DecimalValue
    {
        $literal = $value->toString();
        if (str_contains($literal, '.')) {
            $literal = rtrim(rtrim($literal, '0'), '.');
        }

        return DecimalValue::fromString($literal);
    }
}
