<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

final readonly class Isin
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $submitted): self
    {
        $value = strtoupper(trim($submitted));
        if (1 !== preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D', $value) || !self::hasValidCheckDigit($value)) {
            throw new InvalidInstrument('The ISIN is invalid.', 'invalid_instrument_isin');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function hasValidCheckDigit(string $value): bool
    {
        $expanded = '';
        foreach (str_split($value) as $character) {
            $expanded .= ctype_alpha($character) ? (string) (ord($character) - ord('A') + 10) : $character;
        }

        $sum = 0;
        $double = false;
        for ($index = strlen($expanded) - 1; $index >= 0; --$index) {
            $digit = (int) $expanded[$index];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return 0 === $sum % 10;
    }
}
