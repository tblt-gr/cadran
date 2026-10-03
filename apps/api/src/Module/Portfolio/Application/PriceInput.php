<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

/** One price entry of a per-line valuation, as typed: nothing is validated yet. */
final readonly class PriceInput
{
    public function __construct(public string $instrumentId, public ?string $price, public ?string $priceDate)
    {
    }
}
