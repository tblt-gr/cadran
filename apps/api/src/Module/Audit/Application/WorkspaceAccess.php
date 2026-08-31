<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

/**
 * The audit module's own view of "which workspace may this caller read". It is
 * satisfied by an adapter over the identity module rather than by a direct
 * query, so the read side never learns the shape of membership storage.
 */
interface WorkspaceAccess
{
    /**
     * @param string $userIdentifier the identifier the firewall authenticated
     *
     * @return string|null the workspace the caller may read, or null when the
     *                     caller is unknown, disabled, or has no membership
     */
    public function readableWorkspaceFor(string $userIdentifier): ?string;
}
