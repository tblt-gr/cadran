<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The workspace a signed-in caller resolves to, decided by the test rather
 * than by a session. Like the real resolver, it takes no argument: a use case
 * still cannot name whose scope it wants.
 */
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
