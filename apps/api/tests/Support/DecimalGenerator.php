<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic generator of canonical decimal strings for property tests.
 * It only ever assembles digit strings: no float is used to build or compare a value.
 */
final class DecimalGenerator
{
    public const int DEFAULT_SEED = 539;
    public const int DEFAULT_CASES = 500;

    private readonly Randomizer $random;

    public function __construct(int $seed)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    public static function seed(): int
    {
        return self::environmentInteger('PROPERTY_SEED', self::DEFAULT_SEED);
    }

    public static function cases(): int
    {
        return max(1, self::environmentInteger('PROPERTY_CASES', self::DEFAULT_CASES));
    }

    public function integer(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    public function decimal(int $maxIntegerDigits = 26, int $maxScale = 24, bool $signed = true): string
    {
        $integer = $this->digits($this->integer(1, $maxIntegerDigits), true);
        $scale = $this->integer(0, $maxScale);
        $fraction = $scale > 0 ? rtrim($this->digits($scale, false), '0') : '';

        $literal = '' === $fraction ? $integer : $integer.'.'.$fraction;
        $isZero = 1 !== preg_match('/[1-9]/', $literal);
        if ($signed && !$isZero && 0 === $this->integer(0, 1)) {
            return '-'.$literal;
        }

        return $literal;
    }

    /** A non-zero positive canonical decimal. */
    public function positive(int $maxIntegerDigits = 26, int $maxScale = 24): string
    {
        do {
            $value = $this->decimal($maxIntegerDigits, $maxScale, false);
        } while ('0' === $value);

        return $value;
    }

    private function digits(int $count, bool $noLeadingZero): string
    {
        $digits = '';
        for ($index = 0; $index < $count; ++$index) {
            $low = ($noLeadingZero && $count > 1 && 0 === $index) ? 1 : 0;
            $digits .= (string) $this->integer($low, 9);
        }

        return $digits;
    }

    private static function environmentInteger(string $name, int $default): int
    {
        $raw = getenv($name);
        if (false === $raw || 1 !== preg_match('/^-?[0-9]+$/D', $raw)) {
            return $default;
        }

        return (int) $raw;
    }
}
