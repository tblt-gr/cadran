<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

final readonly class PositionLedgerResult
{
    /**
     * @param array<string, Position>          $positions
     * @param array<string, list<LotMovement>> $movements
     */
    public function __construct(public array $positions, public array $movements)
    {
    }
}
