<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Foundation\Domain\WorkspaceScope;

interface CategorizationWriteLock
{
    /** Serializes every write that can change a preview's rule set or transaction range. */
    public function acquire(WorkspaceScope $workspace): void;
}
