<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class PriceEntry
{
    public function __construct(public ?DecimalValue $price, public ?\DateTimeImmutable $priceDate)
    {
    }
}
