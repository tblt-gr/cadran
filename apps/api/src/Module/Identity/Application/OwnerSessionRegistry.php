<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * The server-side store of open sessions.
 *
 * Cadran is single-owner by design, so every stored session belongs to the one
 * account and "revoke the others" is the whole store minus the caller's own
 * record. A second account would turn this into a per-user query and is the
 * reason the method is named for the intent rather than for the delete.
 */
interface OwnerSessionRegistry
{
    /**
     * Drops every stored session except the one named, so a cookie captured
     * elsewhere stops authenticating immediately.
     *
     * The identifier is a parameter rather than something the adapter digs out
     * of the current request: which session survives a credential change is a
     * decision of the operation, and it must be visible where the transaction
     * is opened. Passing null revokes every session, including the caller's.
     */
    public function revokeAllExcept(?string $sessionIdToKeep): void;
}
