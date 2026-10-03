<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class PortfolioOperationOrderView
{
    /** @param list<string> $operationIds */
    public function __construct(public string $tradeDate, public array $operationIds)
    {
    }
}
