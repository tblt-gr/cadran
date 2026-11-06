<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Takeover;

use App\Module\Foundation\Domain\WorkspaceScope;

interface PortfolioTakeoverRepository
{
    public function findByAccount(WorkspaceScope $workspace, string $accountId): ?PortfolioTakeover;

    public function add(PortfolioTakeover $takeover): void;
}
