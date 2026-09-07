<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * An exact decimal figure in its canonical string form.
 *
 * This value object parses, constrains and orders. ExactDecimal performs
 * arithmetic on it through Brick Math, and no caller may reach for a float.
 *
 * Canonical means one string per recorded figure: an optional `-` only on a
 * non-zero value, at least one integer digit, no leading zero unless the
 * integer part is exactly `0`, no exponent, no separator and no trailing
 * decimal point. Trailing fraction zeros are kept, because they state the
 * scale the source used.
 */
final readonly class DecimalValue
{
    /**
     * NUMERIC(50,24): 24 fraction digits and 26 integer digits. A literal
     * beyond either is refused here rather than rounded by the database.
     */
    public const int MAX_SCALE = 24;
    public const int MAX_INTEGER_DIGITS = 26;

    // The D modifier is not optional: without it PCRE lets `$` match before a
    // trailing newline, and "1.5\n" would pass as canonical with a scale of 2.
    private const string CANONICAL_PATTERN = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D';

    private function __construct(
        private string $literal,
        private int $scale,
    ) {
    }

    public static function fromString(string $literal): self
    {
        if (1 !== preg_match(self::CANONICAL_PATTERN, $literal)) {
            throw new MalformedDecimal('A decimal value must be a canonical decimal string.');
        }

        $unsigned = ltrim($literal, '-');
        $negative = $unsigned !== $literal;

        $parts = explode('.', $unsigned, 2);
        $integerDigits = $parts[0];
        $fractionDigits = $parts[1] ?? '';

        if ($negative && self::isZero($integerDigits, $fractionDigits)) {
            // A sign on zero would give the same figure two canonical forms.
            throw new MalformedDecimal('Zero has no signed form.');
        }

        if (strlen($integerDigits) > self::MAX_INTEGER_DIGITS) {
            throw new PrecisionExceeded(sprintf('A decimal value carries at most %d integer digits.', self::MAX_INTEGER_DIGITS));
        }

        $scale = strlen($fractionDigits);
        if ($scale > self::MAX_SCALE) {
            throw new PrecisionExceeded(sprintf('A decimal value carries at most %d decimal places.', self::MAX_SCALE));
        }

        return new self($literal, $scale);
    }

    public static function zero(): self
    {
        return new self('0', 0);
    }

    public function toString(): string
    {
        return $this->literal;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    /**
     * Refuses a figure the given asset cannot store as submitted. Callers use
     * it before persistence, so the rejection reaches the client instead of a
     * silently shortened value reaching the database.
     */
    public function assertScaleAtMost(int $scale): void
    {
        if ($this->scale > $scale) {
            throw new PrecisionExceeded(sprintf('This value carries more than the %d decimal places accepted here.', $scale));
        }
    }

    public function equals(self $other): bool
    {
        return $this->literal === $other->literal;
    }

    /**
     * Orders two figures exactly: -1, 0 or 1, the way `<=>` reads. The digits
     * are compared as strings, never as floats, so `0.1` and `0.10` compare
     * equal while both stay distinguishable from each other by {@see equals()},
     * which answers about the recorded literal rather than the value.
     */
    public function compareTo(self $other): int
    {
        $negative = $this->isNegative();
        if ($negative !== $other->isNegative()) {
            return $negative ? -1 : 1;
        }

        $magnitudes = self::compareMagnitudes(ltrim($this->literal, '-'), ltrim($other->literal, '-'));

        return $negative ? -$magnitudes : $magnitudes;
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->literal, '-');
    }

    /**
     * Compares two unsigned canonical literals. The canonical form carries no
     * leading zero beyond a lone `0`, so a longer integer part is always the
     * larger one and the digits can then be read side by side.
     */
    private static function compareMagnitudes(string $left, string $right): int
    {
        [$leftInteger, $leftFraction] = self::split($left);
        [$rightInteger, $rightFraction] = self::split($right);

        $byLength = strlen($leftInteger) <=> strlen($rightInteger);
        if (0 !== $byLength) {
            return $byLength;
        }

        $byInteger = strcmp($leftInteger, $rightInteger);
        if (0 !== $byInteger) {
            return $byInteger < 0 ? -1 : 1;
        }

        // Padding on the right aligns the two fractions on the same decimal
        // places, so `5` and `49` compare as 0.50 against 0.49.
        $width = max(strlen($leftFraction), strlen($rightFraction));
        $byFraction = strcmp(str_pad($leftFraction, $width, '0'), str_pad($rightFraction, $width, '0'));

        return $byFraction <=> 0;
    }

    /**
     * @return array{string, string}
     */
    private static function split(string $unsigned): array
    {
        $parts = explode('.', $unsigned, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private static function isZero(string $integerDigits, string $fractionDigits): bool
    {
        return '0' === $integerDigits && '' === rtrim($fractionDigits, '0');
    }
}
