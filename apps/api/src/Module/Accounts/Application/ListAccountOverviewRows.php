<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\AccountOrderRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\RankedAccount;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reference\Application\AssetCatalog;

/**
 * One bounded page of the members of a single overview table, in saved order.
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
     * @param ?string $groupId the primary group, null for the ungrouped section
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
    ): AccountOverviewRowPage {
        $pageSize = self::PAGE_SIZE;

        $workspace = $this->caller->resolve();
        if (null !== $groupId && null === $this->groups->find($workspace, $groupId)) {
            throw new AccountNotFound();
        }

        $version = $this->orders->version($workspace, $groupId);
        $afterRank = 0;
        if (null !== $cursor && '' !== $cursor) {
            $position = $this->cursors->decode($cursor, AccountOrdering::key($groupId));
            if ($position['version'] !== $version) {
                throw new StaleAccountOrder('The order of this group changed since the cursor was issued.');
            }
            $afterRank = $position['rank'];
        }

        $figures = $this->figures->on($workspace, $asOf);
        $ranked = $this->accounts->listInGroupOrder($workspace, $groupId, $includeArchived, $includeClosed, $afterRank, $pageSize + 1);
        $hasMore = count($ranked) > $pageSize;
        $ranked = array_slice($ranked, 0, $pageSize);

        $accounts = array_map(static fn (RankedAccount $row) => $row->account, $ranked);
        $latest = $this->valuations->forAccounts($workspace, $accounts, [$figures->day])[$figures->day->format('Y-m-d')];

        return new AccountOverviewRowPage(
            items: array_map(
                fn ($account): AccountView => AccountView::fromAccount(
                    $account,
                    share: ShareView::fromShare($figures->shares->account($account->id)),
                    valuation: ValuationView::of($account->id, $figures->day, $latest[$account->id], $this->assets->findByCode($account->assetCode)),
                ),
                $accounts,
            ),
            nextCursor: $hasMore && [] !== $ranked
                ? $this->cursors->encode(AccountOrdering::key($groupId), $version, $ranked[count($ranked) - 1]->rank)
                : null,
            orderVersion: $version,
        );
    }
}
