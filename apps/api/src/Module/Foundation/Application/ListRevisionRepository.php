<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface ListRevisionRepository
{
    /** Locks the revision until the caller's transaction finishes. */
    public function lock(WorkspaceScope $workspace, bool $catalogue): string;
}
