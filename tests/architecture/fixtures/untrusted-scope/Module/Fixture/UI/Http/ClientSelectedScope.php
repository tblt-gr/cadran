<?php

declare(strict_types=1);

namespace App\Module\Fixture\UI\Http;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Representative violation: request data must never become an authorized
 * workspace scope without resolving the caller's membership.
 */
final readonly class ClientSelectedScope
{
    public function fromRequest(string $workspaceId): WorkspaceScope
    {
        return WorkspaceScope::fromString($workspaceId);
    }
}
