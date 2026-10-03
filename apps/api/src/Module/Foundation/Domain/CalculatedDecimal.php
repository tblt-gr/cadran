<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRoundingMode;

/**
 * An exact, unpersisted decimal produced by a calculation.
 *
 * Stored inputs remain bounded by DecimalValue and NUMERIC(50,24). A replay may
 * add up to 10 000 such inputs, so its derived totals deliberately have no
 * integer-width ceiling while retaining the 24-place calculation scale.
 */
final readonly class CalculatedDecimal
{
    private function __construct(private string $literal)
    {
    }

    public static function fromDecimal(DecimalValue $value): self
    {
        return new self($value->toString());
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public static function one(): self
    {
        return new self('1');
    }

    public function toString(): string
    {
        return $this->literal;
    }

    public function add(self $other): self
    {
        return self::fromBig($this->big()->plus($other->big()));
    }

    public function subtract(self $other): self
    {
        return self::fromBig($this->big()->minus($other->big()));
    }

    public function negate(): self
    {
        return $this->isZero() ? $this : self::fromBig($this->big()->negated());
    }

    /** Computes this value x numerator / denominator and rounds only the final quotient. */
    public function proportion(self $numerator, self $denominator, int $scale, RoundingMode $roundingMode): self
    {
        $precision = max(0, $scale);
        $result = $this->big()
            ->multipliedBy($numerator->big())
            ->dividedBy($denominator->big(), $precision, self::brickMode($roundingMode));

        return self::fromBig($result->toScale($precision, self::brickMode($roundingMode)));
    }

    public function round(int $scale, RoundingMode $roundingMode): self
    {
        return self::fromBig($this->big()->toScale(max(0, $scale), self::brickMode($roundingMode)));
    }

    public function compareTo(self $other): int
    {
        return $this->big()->compareTo($other->big());
    }

    public function isZero(): bool
    {
        return $this->big()->isZero();
    }

    private function big(): BigDecimal
    {
        return BigDecimal::of($this->literal);
    }

    private static function fromBig(BigDecimal $value): self
    {
        return new self((string) $value);
    }

    private static function brickMode(RoundingMode $roundingMode): BrickRoundingMode
    {
        return match ($roundingMode) {
            RoundingMode::HALF_UP => BrickRoundingMode::HalfUp,
            RoundingMode::HALF_EVEN => BrickRoundingMode::HalfEven,
            RoundingMode::DOWN => BrickRoundingMode::Down,
        };
    }
}
