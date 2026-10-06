<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class OperationLegInput
{
    public function __construct(public string $direction, public string $instrumentId, public ?string $quantity, public ?string $unitPrice, public AmountInput $amount)
    {
    }
}
