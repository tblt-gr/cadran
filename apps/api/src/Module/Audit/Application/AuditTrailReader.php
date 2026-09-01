<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface AuditTrailReader
{
    /**
     * Returns at most $limit entries of the given workspace, newest first,
     * strictly older than $after when it is given. Implementations filter on
     * the workspace unconditionally.
     *
     * @return list<AuditTrailEntry>
     */
    public function readPage(WorkspaceScope $workspace, int $limit, ?AuditTrailCursor $after): array;

    /**
     * Returns the named event, or null when it does not exist *or* belongs to
     * another workspace. The two cases are deliberately indistinguishable: a
     * caller must not be able to probe identifiers to learn what exists
     * elsewhere.
     */
    public function findEvent(WorkspaceScope $workspace, string $eventId): ?AuditTrailEntry;
}
