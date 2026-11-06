<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Portfolio\Domain\Takeover\PortfolioTakeover;

final readonly class PortfolioTakeoverView
{
    public const int MAX_OPERATIONS = TakeoverInput::MAX_LINES + 1;

    /** @param list<string> $operationIds */
    public function __construct(public PortfolioTakeover $takeover, public array $operationIds)
    {
    }
}
