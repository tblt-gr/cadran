<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic on canonical strings, via Brick\Math.
 *
 * {@see DecimalValue} still computes nothing: callers that must add or divide
 * come here, then store the result as a {@see DecimalValue}. Shares keep the
 * storage scale (24) and round HALF_UP, the commercial convention documented
 * with the asset reference.
 */
final class ExactDecimal
{
    public static function add(DecimalValue $left, DecimalValue $right): DecimalValue
    {
        return self::fromBig(self::toBig($left)->plus(self::toBig($right)));
    }

    public static function signed(DecimalValue $value, int $sign): DecimalValue
    {
        return $sign < 0 ? self::fromBig(self::toBig($value)->negated()) : $value;
    }

    public static function divide(DecimalValue $numerator, DecimalValue $denominator, int $scale = DecimalValue::MAX_SCALE): DecimalValue
    {
        $precision = max(0, $scale);

        return self::fromBig(self::toBig($numerator)->dividedBy(self::toBig($denominator), $precision, RoundingMode::HalfUp), $precision);
    }

    public static function timesHundred(DecimalValue $ratio): DecimalValue
    {
        return self::fromBig(self::toBig($ratio)->multipliedBy(100), max(0, $ratio->scale()));
    }

    public static function isZero(DecimalValue $value): bool
    {
        return self::toBig($value)->isZero();
    }

    private static function toBig(DecimalValue $value): BigDecimal
    {
        return BigDecimal::of($value->toString());
    }

    private static function fromBig(BigDecimal $value, ?int $scale = null): DecimalValue
    {
        if (null !== $scale) {
            $value = $value->toScale(max(0, $scale), RoundingMode::HalfUp);
        }

        return DecimalValue::fromString((string) $value);
    }
}
