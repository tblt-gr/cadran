<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

interface AuditTrailReader
{
    /**
     * Returns at most $limit entries of the given workspace, newest first,
     * strictly older than $after when it is given. Implementations filter on
     * the workspace unconditionally.
     *
     * @return list<AuditTrailEntry>
     */
    public function readPage(string $workspaceId, int $limit, ?AuditTrailCursor $after): array;
}
