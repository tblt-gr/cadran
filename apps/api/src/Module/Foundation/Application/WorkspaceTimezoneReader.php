<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

/** Read-only workspace calendar configuration exposed without coupling feature modules to Identity. */
interface WorkspaceTimezoneReader
{
    public function timezone(WorkspaceScope $workspace): string;
}
