<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverBlockReason;

/** The account cannot take over its positions: the reason decides between 409 and 422. */
final class PortfolioTakeoverRefused extends \RuntimeException
{
    public function __construct(public readonly PortfolioTakeoverBlockReason $reason)
    {
        parent::__construct(sprintf('The takeover is refused: %s.', $reason->value));
    }
}
