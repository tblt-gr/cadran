<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;

final readonly class OperationLeg
{
    public ?DecimalValue $roundingResidual;

    public function __construct(
        public int $index,
        public LegDirection $direction,
        public string $instrumentId,
        public DecimalValue $quantity,
        public DecimalValue $unitPrice,
        public DecimalValue $amount,
        int $quantityPrecision,
        int $pricePrecision,
        int $displayPrecision,
    ) {
        if ($index < 1 || $index > 20) {
            throw new InvalidPortfolioOperation('At most twenty support legs are allowed.', 'too_many_operation_legs');
        }
        foreach ([[$quantity, $quantityPrecision, 'quantity_precision_exceeded'], [$unitPrice, $pricePrecision, 'price_precision_exceeded'], [$amount, $displayPrecision, 'invalid_operation_amount']] as [$value, $precision, $code]) {
            if ($value->scale() > $precision) {
                throw new InvalidPortfolioOperation('A leg exceeds its precision.', $code, ['legIndex' => $index]);
            }
            if ($value->isNegative() || ExactDecimal::isZero($value)) {
                throw new InvalidPortfolioOperation('Leg figures must be positive.', $value === $quantity ? 'invalid_operation_quantity' : 'invalid_operation_amount', ['legIndex' => $index]);
            }
        }
        try {
            $expected = ExactDecimal::multiply($quantity, $unitPrice);
            $residual = ExactDecimal::subtract($amount, $expected);
            $tolerance = DecimalValue::fromString('0.'.str_repeat('0', $displayPrecision).'5');
            if (ExactDecimal::absolute($residual)->compareTo($tolerance) > 0) {
                throw new InvalidPortfolioOperation('The leg amount does not match quantity x price.', 'leg_amount_mismatch', ['legIndex' => $index, 'expectedAmount' => $expected->toString()]);
            }
            $this->roundingResidual = ExactDecimal::isZero($residual) ? null : $residual;
        } catch (PrecisionExceeded|MalformedDecimal) {
            throw new InvalidPortfolioOperation('The leg is too large to store exactly.', 'invalid_operation_amount', ['legIndex' => $index]);
        }
    }
}
