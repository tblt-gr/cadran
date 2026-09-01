<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

/**
 * Resolves both the authenticated actor and their server-selected workspace.
 * Mutating use cases need the actor for their audit event, but must never
 * accept either identifier from the request body.
 */
interface CallerWorkspaceContext
{
    public function resolveContext(): WorkspaceContext;
}
