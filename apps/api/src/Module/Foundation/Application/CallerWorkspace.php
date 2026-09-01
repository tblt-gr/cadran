<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Resolves the workspace the authenticated caller may act in.
 *
 * The method deliberately takes no argument. A use case cannot name whose
 * scope it wants, so no request parameter, body field or path segment can ever
 * be routed into it: the answer always comes from the authenticated session
 * itself.
 */
interface CallerWorkspace
{
    /**
     * @throws WorkspaceAccessDenied when the caller is anonymous, unknown,
     *                               credential-less, disabled, or a member of
     *                               no workspace
     */
    public function resolve(): WorkspaceScope;
}
