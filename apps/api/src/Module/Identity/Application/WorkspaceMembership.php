<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final readonly class WorkspaceMembership
{
    public function __construct(
        public string $workspaceId,
        public string $role,
    ) {
    }
}
