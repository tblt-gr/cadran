<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class WorkspaceMembership
{
    public function __construct(
        public WorkspaceScope $workspace,
        public string $role,
    ) {
    }
}
