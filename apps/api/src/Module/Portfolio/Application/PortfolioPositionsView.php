<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class PortfolioPositionsView
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public string $asOf, public array $items)
    {
    }
}
