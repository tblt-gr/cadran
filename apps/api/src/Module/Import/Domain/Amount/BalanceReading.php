<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Amount;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Import\Domain\ReasonCode;

/**
 * A balance or stored figure: empty (null, never zero), rejected, or a value
 * that may legitimately be zero. `rounded` is true when a valuation read
 * leniently had more decimals than the precision and was rounded half-even.
 */
final readonly class BalanceReading
{
    private function __construct(
        public bool $empty,
        public ?ReasonCode $rejection,
        public ?DecimalValue $value,
        public bool $floatArtifact,
        public bool $rounded = false,
    ) {
    }

    public static function empty(): self
    {
        return new self(true, null, null, false);
    }

    public static function rejected(ReasonCode $reason): self
    {
        return new self(false, $reason, null, false);
    }

    public static function of(DecimalValue $value, bool $floatArtifact, bool $rounded = false): self
    {
        return new self(false, null, $value, $floatArtifact, $rounded);
    }
}
