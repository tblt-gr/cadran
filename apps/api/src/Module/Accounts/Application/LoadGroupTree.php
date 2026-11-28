<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The one bounded read of a whole group tree. Archived groups are included:
 * they still carry the lineage and the color of whatever points at them.
 */
final readonly class LoadGroupTree
{
    public function __construct(private AccountGroupRepository $groups)
    {
    }

    /** @throws NetWorthScopeTooLarge rather than answering from a truncated tree */
    public function __invoke(WorkspaceScope $workspace): LoadedGroupTree
    {
        $groups = $this->groups->list($workspace, true, AccountGroup::MAX_PER_WORKSPACE + 1, 0);
        if (count($groups) > AccountGroup::MAX_PER_WORKSPACE) {
            throw new NetWorthScopeTooLarge('This workspace holds more groups than one read allows.');
        }

        return new LoadedGroupTree($groups, AccountGroupTree::lineages($groups));
    }
}
