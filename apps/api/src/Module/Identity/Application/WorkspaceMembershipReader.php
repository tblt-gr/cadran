<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

interface WorkspaceMembershipReader
{
    /**
     * Returns the workspace the user belongs to, scoped by user id. Null when
     * the user has no membership.
     */
    public function findForUser(string $userId): ?WorkspaceMembership;
}
