<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Instrument;

/** A line after validation; a member is null when the typed value was refused (its error is in the resolution). */
final readonly class ResolvedTakeoverLine
{
    public function __construct(
        public string $instrumentId,
        public ?Instrument $instrument,
        public ?DecimalValue $quantity,
        public ?DecimalValue $costBasis,
        public ?\DateTimeImmutable $priceDate,
        public ?DecimalValue $value,
    ) {
    }
}
