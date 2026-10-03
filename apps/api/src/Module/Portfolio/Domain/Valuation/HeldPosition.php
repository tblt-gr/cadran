<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\DecimalValue;

/** A position held on the valuation date, as replayed from the operations. */
final readonly class HeldPosition
{
    public function __construct(public string $instrumentId, public DecimalValue $quantity, public ?DecimalValue $averageCostBasis)
    {
    }
}
