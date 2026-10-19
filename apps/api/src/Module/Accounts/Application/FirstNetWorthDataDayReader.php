<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface FirstNetWorthDataDayReader
{
    /** Earliest active workspace source day on or before the requested day. */
    public function onOrBefore(WorkspaceScope $workspace, \DateTimeImmutable $asOf): ?\DateTimeImmutable;
}
