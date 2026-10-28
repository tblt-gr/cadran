<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupColors;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\AccountOrderRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reference\Application\AssetCatalog;

/**
 * The single financial overview of the accounts: one heading per primary
 * group in tree order, then the ungrouped section.
 *
 * Totals and shares come from the eligible net worth of the requested day.
 * The lifecycle filters only decide how many rows each heading announces.
 */
final readonly class ReadAccountOverview
{
    public function __construct(
        private CallerWorkspace $caller,
        private AccountOverviewFigures $figures,
        private AccountRepository $accounts,
        private AccountOrderRepository $orders,
        private AssetCatalog $assets,
    ) {
    }

    public function __invoke(?string $asOf, bool $includeClosed, bool $includeArchived): AccountOverviewView
    {
        $workspace = $this->caller->resolve();
        $figures = $this->figures->on($workspace, $asOf);
        $counts = $this->accounts->countByPrimaryGroup($workspace, $includeArchived, $includeClosed);
        $versions = $this->orders->versions($workspace);
        $netWorth = $figures->netWorth;
        $reference = null === $netWorth->asset ? null : $this->assets->findByCode($netWorth->asset);
        $totalReason = $netWorth->reason?->value;

        $parentsWithChildren = [];
        foreach ($figures->groups as $group) {
            if (null !== $group->parentId && null === $group->archivedAt) {
                $parentsWithChildren[$group->parentId] = true;
            }
        }

        $colors = AccountGroupColors::of($figures->groups);
        $headings = [];
        foreach (AccountGroupTree::depthFirst($figures->groups) as $group) {
            $members = $counts[$group->id] ?? 0;
            if (null !== $group->archivedAt && 0 === $members) {
                continue;
            }

            $headings[] = AccountOverviewGroupView::ofGroup(
                $group,
                $colors,
                isset($parentsWithChildren[$group->id]),
                $members,
                NetWorthAmountView::of($figures->rollup[$group->id] ?? null, $netWorth->asset, $reference),
                $totalReason,
                ShareView::fromShare($figures->shares->group($group->id)),
                $figures->qualityOf($group->id)->value,
                $versions[$group->id] ?? 0,
            );
        }

        $ungrouped = $counts[AccountOrdering::UNGROUPED_KEY] ?? 0;
        if ($ungrouped > 0) {
            $headings[] = new AccountOverviewGroupView(
                groupId: null,
                label: null,
                parentId: null,
                depth: 0,
                color: null,
                resolvedColor: null,
                defaultColor: null,
                isRollup: false,
                memberCount: $ungrouped,
                total: NetWorthAmountView::of($figures->ungroupedTotal, $netWorth->asset, $reference),
                totalReason: $totalReason,
                share: ShareView::fromShare($figures->shares->ungrouped()),
                quality: $figures->qualityOf(null)->value,
                orderVersion: $versions[AccountOrdering::UNGROUPED_KEY] ?? 0,
                archivedAt: null,
            );
        }

        return new AccountOverviewView(
            asOf: $figures->day->format('Y-m-d'),
            accountCount: $this->accounts->count($workspace, true, true),
            groups: $headings,
        );
    }
}
