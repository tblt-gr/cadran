<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupColors;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\AccountOrderRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reference\Application\AssetCatalog;

/**
 * The single financial overview of the accounts: one heading per root group
 * (roll-up) or per primary group (direct), in tree order, then the ungrouped
 * section.
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

    public function __invoke(
        ?string $asOf,
        bool $includeClosed,
        bool $includeArchived,
        AccountOverviewGrouping $grouping = AccountOverviewGrouping::PARENT,
    ): AccountOverviewView {
        $workspace = $this->caller->resolve();
        $figures = $this->figures->on($workspace, $asOf);
        $counts = $this->accounts->countByPrimaryGroup($workspace, $includeArchived, $includeClosed);
        $versions = $this->orders->versions($workspace);
        $netWorth = $figures->netWorth;
        $reference = null === $netWorth->asset ? null : $this->assets->findByCode($netWorth->asset);
        $totalReason = $netWorth->reason?->value;

        $colors = AccountGroupColors::of($figures->groups);
        $labels = [];
        foreach ($figures->groups as $group) {
            $labels[$group->id] = $group->label;
        }
        $subtreeCounts = self::subtreeCounts($figures->lineages, $counts);

        $headings = [];
        foreach (AccountGroupTree::depthFirst($figures->groups) as $group) {
            $parent = null !== $group->parentId && isset($labels[$group->parentId]) ? $group->parentId : null;
            if (AccountOverviewGrouping::PARENT === $grouping) {
                if (null !== $parent) {
                    continue;
                }
                $members = $subtreeCounts[$group->id] ?? 0;
                $total = $figures->rollup[$group->id] ?? null;
                $share = $figures->shares->group($group->id);
            } else {
                $members = $counts[$group->id] ?? 0;
                $total = $figures->direct[$group->id] ?? null;
                $share = $figures->shares->directGroup($group->id);
            }
            if (0 === $members) {
                continue;
            }

            $headings[] = AccountOverviewGroupView::ofGroup(
                $group,
                $colors,
                $members,
                NetWorthAmountView::of($total, $netWorth->asset, $reference),
                $totalReason,
                ShareView::fromShare($share),
                $figures->qualityOf($group->id)->value,
                $this->sectionVersion($grouping, $group->id, $figures->lineages, $versions),
                AccountOverviewGrouping::PRIMARY === $grouping && null !== $parent ? $labels[$parent] : null,
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

    /**
     * Visible members of each group's whole subtree, from the direct counts.
     *
     * @param list<GroupLineage> $lineages
     * @param array<string, int> $counts   direct visible members keyed by {@see AccountOrdering::key()}
     *
     * @return array<string, int>
     */
    private static function subtreeCounts(array $lineages, array $counts): array
    {
        $totals = [];
        foreach ($lineages as $lineage) {
            $direct = $counts[$lineage->groupId] ?? 0;
            foreach ($lineage->ancestorIdsIncludingSelf as $ancestorId) {
                $totals[$ancestorId] = ($totals[$ancestorId] ?? 0) + $direct;
            }
        }

        return $totals;
    }

    /**
     * Any move inside a subtree bumps one of its groups, so the sum of their
     * versions is what a roll-up section announces.
     *
     * @param list<GroupLineage> $lineages
     * @param array<string, int> $versions
     */
    private function sectionVersion(AccountOverviewGrouping $grouping, string $groupId, array $lineages, array $versions): int
    {
        return AccountOverviewGrouping::PARENT === $grouping
            ? SubtreeOrderVersion::of($groupId, $lineages, $versions)
            : $versions[$groupId] ?? 0;
    }
}
