<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * An exact decimal figure in its canonical string form.
 *
 * This value object parses and constrains; it deliberately computes nothing.
 * Exact arithmetic arrives with the decimal value objects of DEC-001, and no
 * caller may reach for a float in the meantime.
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

    private static function isZero(string $integerDigits, string $fractionDigits): bool
    {
        return '0' === $integerDigits && '' === rtrim($fractionDigits, '0');
    }
}
