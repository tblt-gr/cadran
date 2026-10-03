<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class PortfolioLotsView
{
    /**
     * @param array<string, mixed>                         $instrument
     * @param list<array<string, mixed>>                   $openLots
     * @param list<array<string, mixed>>                   $movements
     * @param array{value: string, assetCode: string}|null $fifoCostBasis
     */
    public function __construct(public string $asOf, public array $instrument, public array $openLots, public array $movements, public ?array $fifoCostBasis)
    {
    }
}
