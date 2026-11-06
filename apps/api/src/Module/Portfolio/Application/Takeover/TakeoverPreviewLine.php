<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

final readonly class TakeoverPreviewLine
{
    public function __construct(public string $instrumentId, public ?string $value, public ?string $averageUnitCost)
    {
    }
}
