<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface FirstPortfolioValuationDayReader
{
    public function onOrBefore(WorkspaceScope $workspace, \DateTimeImmutable $asOf): ?\DateTimeImmutable;
}
