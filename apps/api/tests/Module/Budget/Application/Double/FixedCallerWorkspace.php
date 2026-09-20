<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Double;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class FixedCallerWorkspace implements CallerWorkspace, CallerWorkspaceContext
{
    public function __construct(
        private string $workspaceId,
        private string $actorId = '00000000-0000-7000-8000-000000000001',
        private bool $isOwner = true,
    ) {
    }

    public function resolve(): WorkspaceScope
    {
        return WorkspaceScope::fromString($this->workspaceId);
    }

    public function resolveContext(): WorkspaceContext
    {
        return new WorkspaceContext($this->resolve(), $this->actorId, $this->isOwner);
    }
}
