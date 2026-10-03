<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\DecimalValue;

/** The state of one instrument frozen with its valuation: it is never recomputed. */
final readonly class PositionSnapshot
{
    public function __construct(
        public string $id,
        public string $valuationId,
        public string $instrumentId,
        public DecimalValue $quantity,
        public ?DecimalValue $unitPrice,
        public ?\DateTimeImmutable $priceDate,
        public ?PriceSource $priceSource,
        public ?DecimalValue $marketValue,
        public ?DecimalValue $averageCostBasis,
        public PriceQuality $priceQuality,
    ) {
    }
}
