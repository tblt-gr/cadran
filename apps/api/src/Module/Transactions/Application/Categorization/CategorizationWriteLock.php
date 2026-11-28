<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * One lock order for every write that touches recurrences, refunds or
 * categorization, so no two of them can wait on each other:
 *
 * 1. the workspace row, through {@see self::acquire()};
 * 2. the recurrence row;
 * 3. transaction rows;
 * 4. occurrence rows.
 *
 * The lock is held until the database transaction ends and is re-entrant inside
 * it: a nested use case acquiring it again is a no-op.
 */
interface CategorizationWriteLock
{
    /** Serializes every write that can change a preview's rule set or transaction range. */
    public function acquire(WorkspaceScope $workspace): void;
}
