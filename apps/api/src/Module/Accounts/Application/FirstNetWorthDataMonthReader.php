<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface FirstNetWorthDataMonthReader
{
    /** Earliest month of active source data on or before the requested day, in YYYY-MM form. */
    public function onOrBefore(WorkspaceScope $workspace, \DateTimeImmutable $asOf): ?string;
}
