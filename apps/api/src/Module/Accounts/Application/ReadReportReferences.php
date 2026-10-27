<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Publishes the account and group ids a report column may name, without leaking any other workspace.
 * A group is labelled by its full path, `Investissement › Bourse`.
 */
final readonly class ReadReportReferences
{
    public const string PATH_SEPARATOR = ' › ';

    public function __construct(
        private AccountRepository $accounts,
        private AccountGroupRepository $groups,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace): ReportReferences
    {
        $accounts = $this->accounts->list($workspace, true, true, ReadMonthlyAccountFacts::MAX_ACCOUNTS + 1, 0);
        $groups = $this->groups->list($workspace, true, ReadNetWorth::MAX_GROUPS + 1, 0);
        if (count($accounts) > ReadMonthlyAccountFacts::MAX_ACCOUNTS || count($groups) > ReadNetWorth::MAX_GROUPS) {
            throw new NetWorthScopeTooLarge('A report reads at most 500 accounts and 500 groups.');
        }
        $references = array_map(static fn (Account $account): ReportAccountReference => new ReportAccountReference(
            $account->id,
            $account->label,
            $account->kind->value,
            $account->includeInNetWorth,
            null !== $account->archivedAt,
            $account->openedOn->format('Y-m-d'),
            $account->institution,
        ), $accounts);
        usort($references, static fn (ReportAccountReference $a, ReportAccountReference $b): int => [$a->label, $a->id] <=> [$b->label, $b->id]);
        // A sub-group is told apart from a namesake elsewhere in the tree by its path, listed in
        // the order the accounts page reads the tree.
        $byId = [];
        foreach ($groups as $group) {
            $byId[$group->id] = $group;
        }
        $labels = [];
        foreach (AccountGroupTree::depthFirst($groups) as $group) {
            $path = [$group->label];
            for ($cursor = $group, $guard = 0; null !== $cursor->parentId && isset($byId[$cursor->parentId]) && $guard < AccountGroup::MAX_TREE_DEPTH; ++$guard) {
                $cursor = $byId[$cursor->parentId];
                array_unshift($path, $cursor->label);
            }
            $labels[$group->id] = implode(self::PATH_SEPARATOR, $path);
        }

        return new ReportReferences($references, $labels);
    }
}
