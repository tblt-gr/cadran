<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\DecimalValue;

/** A computed line, before it receives its snapshot identity. */
final readonly class ValuationLine
{
    public function __construct(
        public string $instrumentId,
        public DecimalValue $quantity,
        public ?DecimalValue $unitPrice,
        public ?\DateTimeImmutable $priceDate,
        public ?DecimalValue $marketValue,
        public ?DecimalValue $averageCostBasis,
        public PriceQuality $priceQuality,
    ) {
    }
}
