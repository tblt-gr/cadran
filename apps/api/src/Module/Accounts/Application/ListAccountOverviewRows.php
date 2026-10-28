<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\AccountOrderRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\RankedAccount;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reference\Application\AssetCatalog;

/**
 * One bounded page of the members of a single overview table, in saved order
 * (depth-first group order then saved order for a roll-up section).
 *
 * Each row carries its balance resolved through the shared valuation and its
 * share of the same eligible net worth the headings use.
 */
final readonly class ListAccountOverviewRows
{
    public const int PAGE_SIZE = 50;

    public function __construct(
        private CallerWorkspace $caller,
        private AccountOverviewFigures $figures,
        private AccountGroupRepository $groups,
        private AccountRepository $accounts,
        private AccountOrderRepository $orders,
        private ResolveAccountValuations $valuations,
        private AssetCatalog $assets,
        private AccountOverviewCursorCodec $cursors,
    ) {
    }

    /**
     * @param ?string $groupId the section group, null for the ungrouped section
     *
     * @throws AccountNotFound             when the group is unknown to the caller's workspace
     * @throws InvalidAccountOverviewQuery on a malformed bound, date or cursor
     * @throws StaleAccountOrder           when the cursor was read before the last reorder
     */
    public function __invoke(
        ?string $groupId,
        ?string $cursor,
        ?string $asOf,
        bool $includeClosed,
        bool $includeArchived,
        AccountOverviewGrouping $grouping = AccountOverviewGrouping::PARENT,
    ): AccountOverviewRowPage {
        $pageSize = self::PAGE_SIZE;

        $workspace = $this->caller->resolve();
        if (null !== $groupId && null === $this->groups->find($workspace, $groupId)) {
            throw new AccountNotFound();
        }

        $figures = $this->figures->on($workspace, $asOf);
        $versions = $this->orders->versions($workspace);
        $subtree = null === $groupId ? [] : $this->subtree($figures, $groupId, $grouping);
        $version = null === $groupId || [$groupId] === $subtree
            ? $versions[AccountOrdering::key($groupId)] ?? 0
            : SubtreeOrderVersion::of($groupId, $figures->lineages, $versions);

        $afterRank = 0;
        if (null !== $cursor && '' !== $cursor) {
            $position = $this->cursors->decode($cursor, AccountOrdering::key($groupId), $grouping);
            if ($position['version'] !== $version) {
                throw new StaleAccountOrder('The order of this group changed since the cursor was issued.');
            }
            $afterRank = $position['rank'];
        }

        $ranked = [] === $subtree || [$groupId] === $subtree
            ? $this->accounts->listInGroupOrder($workspace, $groupId, $includeArchived, $includeClosed, $afterRank, $pageSize + 1)
            : $this->accounts->listInGroupsOrder($workspace, $subtree, $includeArchived, $includeClosed, $afterRank, $pageSize + 1);
        $hasMore = count($ranked) > $pageSize;
        $ranked = array_slice($ranked, 0, $pageSize);

        $accounts = array_map(static fn (RankedAccount $row) => $row->account, $ranked);
        $latest = $this->valuations->forAccounts($workspace, $accounts, [$figures->day])[$figures->day->format('Y-m-d')];

        $labels = [];
        $parents = [];
        foreach ($figures->groups as $group) {
            $labels[$group->id] = $group->label;
            $parents[$group->id] = $group->parentId;
        }

        return new AccountOverviewRowPage(
            items: array_map(
                function (Account $account) use ($figures, $latest, $labels, $parents, $versions, $groupId): AccountOverviewRow {
                    $own = $account->primaryGroupId;
                    $parent = null === $own || $own === $groupId ? null : $parents[$own] ?? null;

                    return new AccountOverviewRow(
                        AccountView::fromAccount(
                            $account,
                            share: ShareView::fromShare($figures->shares->account($account->id)),
                            valuation: ValuationView::of($account->id, $figures->day, $latest[$account->id], $this->assets->findByCode($account->assetCode)),
                        ),
                        null === $own ? null : $labels[$own] ?? null,
                        null === $parent ? null : $labels[$parent] ?? null,
                        $versions[AccountOrdering::key($own)] ?? 0,
                    );
                },
                $accounts,
            ),
            nextCursor: $hasMore && [] !== $ranked
                ? $this->cursors->encode(AccountOrdering::key($groupId), $grouping, $version, $ranked[count($ranked) - 1]->rank)
                : null,
            orderVersion: $version,
        );
    }

    /**
     * The primary groups a section reads, in depth-first order: the group
     * itself in sub-group mode, its whole subtree in parent mode.
     *
     * @return list<string>
     */
    private function subtree(OverviewFigures $figures, string $groupId, AccountOverviewGrouping $grouping): array
    {
        if (AccountOverviewGrouping::PRIMARY === $grouping) {
            return [$groupId];
        }

        $inside = [];
        foreach ($figures->lineages as $lineage) {
            if (in_array($groupId, $lineage->ancestorIdsIncludingSelf, true)) {
                $inside[$lineage->groupId] = true;
            }
        }

        $ordered = [];
        foreach (AccountGroupTree::depthFirst($figures->groups) as $group) {
            if (isset($inside[$group->id])) {
                $ordered[] = $group->id;
            }
        }

        return $ordered;
    }
}
