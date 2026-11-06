<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverBlockReason;

final readonly class PortfolioTakeoverState
{
    public function __construct(
        public bool $eligible,
        public ?PortfolioTakeoverBlockReason $reason,
        public \DateTimeImmutable $defaultDate,
        public ?PortfolioTakeoverView $takeover,
    ) {
    }
}
