<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRoundingMode;

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

    public static function subtract(DecimalValue $left, DecimalValue $right): DecimalValue
    {
        return self::fromBig(self::toBig($left)->minus(self::toBig($right)));
    }

    public static function sum(DecimalValue ...$values): DecimalValue
    {
        if ([] === $values) {
            return DecimalValue::zero();
        }

        $sum = $values[0];
        foreach (array_slice($values, 1) as $value) {
            $sum = self::add($sum, $value);
        }

        return $sum;
    }

    public static function negate(DecimalValue $value): DecimalValue
    {
        return self::isZero($value) ? $value : self::fromBig(self::toBig($value)->negated());
    }

    public static function absolute(DecimalValue $value): DecimalValue
    {
        return $value->isNegative() ? self::negate($value) : $value;
    }

    public static function multiply(DecimalValue $left, DecimalValue $right): DecimalValue
    {
        return self::fromBig(self::toBig($left)->multipliedBy(self::toBig($right)));
    }

    /**
     * Multiplies for an API-only calculated decimal. The natural product scale
     * is preserved through the transport ceiling, then rounded HALF_UP to at
     * most 24 places. Unlike a stored DecimalValue, the returned canonical
     * string may carry more than 26 integer digits because it is never written
     * to NUMERIC(50,24).
     */
    public static function multiplyForResponse(DecimalValue $left, DecimalValue $right): string
    {
        $product = self::toBig($left)->multipliedBy(self::toBig($right));
        $scale = max(0, min($left->scale() + $right->scale(), DecimalValue::MAX_SCALE));

        return (string) $product->toScale($scale, BrickRoundingMode::HalfUp);
    }

    /** Multiplies an unbounded calculated response value by a stored decimal. */
    public static function multiplyCanonicalForResponse(string $left, DecimalValue $right): string
    {
        $product = BigDecimal::of($left)->multipliedBy(self::toBig($right));
        $leftScale = BigDecimal::of($left)->getScale();
        $scale = max(0, min($leftScale + $right->scale(), DecimalValue::MAX_SCALE));

        return (string) $product->toScale($scale, BrickRoundingMode::HalfUp);
    }

    /**
     * Subtracts API-only calculated decimals which may legitimately exceed the
     * storage integer width after a ratio multiplication. Both inputs are
     * internal canonical strings, never untrusted request values.
     */
    public static function subtractForResponse(string $left, string $right): string
    {
        return (string) BigDecimal::of($left)->minus(BigDecimal::of($right));
    }

    /** Adds API-only calculated decimals without applying the storage width. */
    public static function addForResponse(string $left, string $right): string
    {
        return (string) BigDecimal::of($left)->plus(BigDecimal::of($right));
    }

    /** Negates an API-only calculated decimal while preserving its scale. */
    public static function negateForResponse(string $value): string
    {
        return (string) BigDecimal::of($value)->negated();
    }

    /** Orders API-only calculated decimals without converting through a float. */
    public static function compareForResponse(string $left, string $right): int
    {
        return BigDecimal::of($left)->compareTo(BigDecimal::of($right));
    }

    public static function isZeroForResponse(string $value): bool
    {
        return BigDecimal::of($value)->isZero();
    }

    public static function signed(DecimalValue $value, int $sign): DecimalValue
    {
        return $sign < 0 ? self::fromBig(self::toBig($value)->negated()) : $value;
    }

    public static function divide(DecimalValue $numerator, DecimalValue $denominator, int $scale = DecimalValue::MAX_SCALE): DecimalValue
    {
        $precision = max(0, $scale);

        return self::fromBig(self::toBig($numerator)->dividedBy(self::toBig($denominator), $precision, BrickRoundingMode::HalfUp), $precision);
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

    /**
     * Rounds at a named presentation boundary. The source figure is not
     * mutated: callers keep it and store this result only where a screen or
     * export needs fewer decimals than were submitted.
     */
    public static function round(DecimalValue $value, int $scale, RoundingMode $mode): DecimalValue
    {
        $brickMode = match ($mode) {
            RoundingMode::HALF_UP => BrickRoundingMode::HalfUp,
            RoundingMode::HALF_EVEN => BrickRoundingMode::HalfEven,
            RoundingMode::DOWN => BrickRoundingMode::Down,
        };

        return self::fromBig(self::toBig($value)->toScale(max(0, $scale), $brickMode), $scale, $brickMode);
    }

    private static function fromBig(BigDecimal $value, ?int $scale = null, BrickRoundingMode $mode = BrickRoundingMode::HalfUp): DecimalValue
    {
        if (null !== $scale) {
            $value = $value->toScale(max(0, $scale), $mode);
        }

        return DecimalValue::fromString((string) $value);
    }
}
