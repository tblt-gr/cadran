<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;

/** Pure conversions and guards shared by the position replay. */
final class LedgerValues
{
    private function __construct()
    {
    }

    /** @param list<OpenLot> $lots */
    public static function lotCost(array $lots): ?CalculatedDecimal
    {
        $cost = CalculatedDecimal::zero();
        foreach ($lots as $lot) {
            if (null === $lot->costBasis) {
                return null;
            }
            $cost = $cost->add($lot->costBasis);
        }

        return $cost;
    }

    public static function acquisitionCost(?DecimalValue $gross, ?DecimalValue $fees, ?DecimalValue $taxes): CalculatedDecimal
    {
        return self::calculated(self::requiredDecimal($gross))
            ->add(self::nullableCalculated($fees) ?? CalculatedDecimal::zero())
            ->add(self::nullableCalculated($taxes) ?? CalculatedDecimal::zero());
    }

    public static function calculated(DecimalValue $value): CalculatedDecimal
    {
        return CalculatedDecimal::fromDecimal($value);
    }

    /** Derived amounts can carry insignificant zeros beyond the support's scale; quantities must round-trip through input validation. */
    public static function derivedQuantity(DecimalValue $value): CalculatedDecimal
    {
        $literal = $value->toString();
        $normalized = str_contains($literal, '.') ? rtrim(rtrim($literal, '0'), '.') : $literal;

        return self::calculated(DecimalValue::fromString($normalized));
    }

    public static function nullableCalculated(?DecimalValue $value): ?CalculatedDecimal
    {
        return null === $value ? null : self::calculated($value);
    }

    public static function required(?string $value): string
    {
        return $value ?? throw new \LogicException('The operation field matrix guarantees this identifier.');
    }

    public static function requiredDecimal(?DecimalValue $value): DecimalValue
    {
        return $value ?? throw new \LogicException('The operation field matrix guarantees this decimal.');
    }
}
