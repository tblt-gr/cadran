<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;

final readonly class PositionLedgerResult
{
    /**
     * @param array<string, Position>           $positions
     * @param array<string, list<LotMovement>>  $movements
     * @param array<string, ?CalculatedDecimal> $averageCostReleased operation identifier => average-pool cost a SELL or SECURITY_TRANSFER_OUT removes, null while the pool is unknown
     */
    public function __construct(public array $positions, public array $movements, public array $averageCostReleased = [])
    {
    }
}
