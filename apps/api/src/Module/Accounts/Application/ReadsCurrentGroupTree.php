<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

/** Port through which other modules read the current group tree of one workspace. */
interface ReadsCurrentGroupTree
{
    /** @throws NetWorthScopeTooLarge when the workspace holds more accounts or groups than one read allows */
    public function __invoke(WorkspaceScope $workspace): CurrentGroupTree;
}
