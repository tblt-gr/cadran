<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionLedgerResult;

/**
 * The active operations of one account, read once, with what {@see ReconcilePortfolioPositions} already replayed from
 * them. It lives for one request and is passed along, never held by a service.
 */
final class LoadedOperationHistory
{
    public ?PositionLedgerResult $ledger = null;

    /** @var array<string, array<string, CalculatedDecimal>|null>|null non-zero quantities at the end of each trade day, `Y-m-d` ascending */
    public ?array $quantitiesByTradeDay = null;

    /** @param list<PortfolioOperation> $operations */
    public function __construct(public readonly array $operations)
    {
    }
}
