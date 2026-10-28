<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application\Double;

use App\Module\Accounts\Application\CurrentGroupTree;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadsCurrentGroupTree;
use App\Module\Foundation\Domain\WorkspaceScope;

/** A group tree per workspace, so a test can prove a read never crosses one. */
final class FixedGroupTree implements ReadsCurrentGroupTree
{
    /** @var array<string, CurrentGroupTree> */
    private array $trees = [];
    public int $reads = 0;
    public bool $tooLarge = false;

    public function with(WorkspaceScope $workspace, CurrentGroupTree $tree): self
    {
        $this->trees[$workspace->id] = $tree;

        return $this;
    }

    public function __invoke(WorkspaceScope $workspace): CurrentGroupTree
    {
        ++$this->reads;
        if ($this->tooLarge) {
            throw new NetWorthScopeTooLarge('Too many accounts.');
        }

        return $this->trees[$workspace->id] ?? new CurrentGroupTree([], []);
    }
}
