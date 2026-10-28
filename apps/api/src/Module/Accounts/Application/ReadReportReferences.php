<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountGroupColors;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Publishes the account and group ids a report column may name, without leaking any other workspace.
 * A group is labelled by its own name, with the label of its immediate parent beside it.
 */
final readonly class ReadReportReferences
{
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
        // A sub-group keeps its own name; the label of its immediate parent travels beside it so a
        // namesake elsewhere in the tree can be told apart. Listed in the accounts page order.
        $byId = [];
        foreach ($groups as $group) {
            $byId[$group->id] = $group;
        }
        $labels = [];
        $parents = [];
        foreach (AccountGroupTree::depthFirst($groups) as $group) {
            $labels[$group->id] = $group->label;
            $parents[$group->id] = null === $group->parentId ? null : ($byId[$group->parentId]->label ?? null);
        }

        return new ReportReferences($references, $labels, $parents, AccountGroupColors::resolveAll($groups));
    }
}
