<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

/** One line held at the takeover date, as typed: nothing is validated yet. */
final readonly class TakeoverLineInput
{
    public function __construct(
        public string $instrumentId,
        public string $quantity,
        public ?string $costBasis,
        public string $price,
        public ?string $priceDate,
    ) {
    }
}
