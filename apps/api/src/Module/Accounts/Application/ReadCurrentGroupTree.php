<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Reads the active groups and the primary group of every account of one
 * workspace, and nothing of any other. Archived groups hold no account, so
 * they drop out of the lineages without losing a member.
 */
final readonly class ReadCurrentGroupTree implements ReadsCurrentGroupTree
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountGroupRepository $groups,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace): CurrentGroupTree
    {
        $accounts = $this->accounts->list($workspace, true, true, ReadMonthlyAccountFacts::MAX_ACCOUNTS + 1, 0);
        $groups = $this->groups->list($workspace, true, ReadNetWorth::MAX_GROUPS + 1, 0);
        if (count($accounts) > ReadMonthlyAccountFacts::MAX_ACCOUNTS || count($groups) > ReadNetWorth::MAX_GROUPS) {
            throw new NetWorthScopeTooLarge('A group tree read covers at most 500 accounts and 500 groups.');
        }

        $primary = [];
        foreach ($accounts as $account) {
            $primary[$account->id] = $account->primaryGroupId;
        }

        return new CurrentGroupTree(
            AccountGroupTree::lineages(AccountGroupTree::depthFirst(array_values(array_filter(
                $groups,
                static fn ($group): bool => null === $group->archivedAt,
            )))),
            $primary,
        );
    }
}
