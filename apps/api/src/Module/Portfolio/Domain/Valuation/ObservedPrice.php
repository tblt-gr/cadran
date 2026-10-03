<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\DecimalValue;

/** A price entered on an active valuation, with the valuation it came from. */
final readonly class ObservedPrice
{
    public function __construct(
        public string $instrumentId,
        public DecimalValue $price,
        public \DateTimeImmutable $priceDate,
        public string $valuationId,
        public \DateTimeImmutable $recordedAt,
    ) {
    }
}
