<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

final readonly class VenueMic
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $submitted): self
    {
        $value = strtoupper(trim($submitted));
        if (1 !== preg_match('/^[A-Z0-9]{4}$/D', $value)) {
            throw new InvalidInstrument('The venue MIC is invalid.', 'invalid_instrument_venue');
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
}
