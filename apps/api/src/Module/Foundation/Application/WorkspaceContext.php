<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class WorkspaceContext
{
    public function __construct(
        public WorkspaceScope $workspace,
        public string $actorId,
        /** Only a membership role of OWNER sets this; it defaults to the safe answer. */
        public bool $isOwner = false,
    ) {
        if ('' === $actorId) {
            throw new \InvalidArgumentException('A workspace context requires an actor.');
        }
    }
}
