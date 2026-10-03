<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Instrument;

final readonly class DraftPosition
{
    /** @param string|null $originKind VALUATION or OPERATION */
    public function __construct(
        public Instrument $instrument,
        public DecimalValue $quantity,
        public ?DecimalValue $lastPrice,
        public ?\DateTimeImmutable $lastPriceDate,
        public ?string $originKind,
        public ?string $originId,
    ) {
    }
}
