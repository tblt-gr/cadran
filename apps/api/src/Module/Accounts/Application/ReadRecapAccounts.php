<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reference\Application\AssetCatalog;

/** Bounded owned-account selection, independent from net-worth eligibility. */
final readonly class ReadRecapAccounts
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountGroupRepository $groups,
        private ResolveAccountValuations $valuations,
        private AssetCatalog $assets,
    ) {
    }

    /** @return list<AccountView> */
    public function choices(WorkspaceScope $workspace): array
    {
        return array_map(AccountView::fromAccount(...), $this->ordered($workspace));
    }

    /**
     * @param ?list<string> $ids null uses the first six active current accounts
     *
     * @return list<AccountView>
     */
    public function on(WorkspaceScope $workspace, ?array $ids, \DateTimeImmutable $day): array
    {
        if ([] === $ids) {
            return [];
        }
        $owned = $this->ordered($workspace);
        if (null === $ids) {
            $selected = array_slice(array_values(array_filter($owned, static fn ($account): bool => AccountKind::CURRENT === $account->kind && null === $account->archivedAt && !$account->isClosed())), 0, 6);
        } else {
            $byId = [];
            foreach ($owned as $account) {
                $byId[$account->id] = $account;
            }
            $selected = [];
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $selected[] = $byId[$id];
                }
            }
        }
        $latest = $this->valuations->forAccounts($workspace, $selected, [$day])[$day->format('Y-m-d')];

        return array_map(fn ($account): AccountView => AccountView::fromAccount(
            $account,
            valuation: ValuationView::of($account->id, $day,
                $latest[$account->id],
                $this->assets->findByCode($account->assetCode)),
        ), $selected);
    }

    /** @return list<\App\Module\Accounts\Domain\Account> */
    private function ordered(WorkspaceScope $workspace): array
    {
        $accounts = $this->accounts->listInSavedOrder($workspace, ReadMonthlyAccountFacts::MAX_ACCOUNTS + 1);
        $groups = $this->groups->list($workspace, true, ReadNetWorth::MAX_GROUPS + 1, 0);
        if (count($accounts) > ReadMonthlyAccountFacts::MAX_ACCOUNTS || count($groups) > ReadNetWorth::MAX_GROUPS) {
            throw new NetWorthScopeTooLarge('A recap selection reads at most 500 accounts and 500 groups.');
        }
        $members = [];
        foreach ($accounts as $account) {
            $members[$account->primaryGroupId ?? ''][] = $account;
        }
        $ordered = [];
        foreach (AccountGroupTree::depthFirst($groups) as $group) {
            array_push($ordered, ...($members[$group->id] ?? []));
        }
        array_push($ordered, ...($members[''] ?? []));

        return $ordered;
    }
}
