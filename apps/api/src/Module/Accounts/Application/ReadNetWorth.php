<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\ExclusiveGroupRollup;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Accounts\Domain\NetWorth;
use App\Module\Accounts\Domain\NetWorthDelta;
use App\Module\Accounts\Domain\NetWorthShares;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reference\Application\AssetCatalog;

/**
 * The explainable net worth of the calling workspace on one business day.
 *
 * Everything a reader needs to disagree with the figure travels with it: the
 * contributing accounts, the exclusive allocation, the freshness of each
 * valuation and the movement since a compared day.
 */
final readonly class ReadNetWorth
{
    public function __construct(
        private CallerWorkspace $caller,
        private ResolveNetWorthContributions $contributions,
        private LoadGroupTree $groupTree,
        private AssetCatalog $assets,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(?string $asOf = null, ?string $comparedTo = null): NetWorthView
    {
        $today = $this->calendar->today();
        $requestedOn = NetWorthDay::parse($asOf, $today);
        $comparedOn = null === $comparedTo || '' === $comparedTo
            ? NetWorthDay::previousMonth($requestedOn)
            : NetWorthDay::parse($comparedTo, $today);

        if ($comparedOn >= $requestedOn) {
            throw new InvalidNetWorthQuery('The compared day must precede the requested day.');
        }

        $workspace = $this->caller->resolve();
        $set = $this->contributions->on($workspace, [$requestedOn, $comparedOn]);
        $currentContributions = $set->on($requestedOn);
        $previousContributions = $set->on($comparedOn);

        // Archived buckets still carry the lineage of the accounts that point
        // at them, so they are read for the roll-up and dropped from the
        // published allocation rather than being missing from both.
        $tree = ($this->groupTree)($workspace);
        $groups = $tree->groups;
        $currentFigures = NetWorthDayFigures::of($currentContributions, $requestedOn, $tree->lineages);
        $previousFigures = NetWorthDayFigures::of($previousContributions, $comparedOn, $tree->lineages);
        $current = $currentFigures->netWorth;
        $previous = $previousFigures->netWorth;
        $shares = $currentFigures->shares();
        $references = new NetWorthAssetReferences($this->assets);

        return NetWorthView::of(
            $current,
            NetWorthDeltaView::fromDelta(
                NetWorthDelta::between($current, $previous),
                $previous,
                $references,
                $set->sourceAccountIds($comparedOn),
                $set->sourceAccountIds($requestedOn),
                StaleValuationView::listFor($set->accounts, $previousContributions),
            ),
            self::contributionViews($set, $current, $groups, $shares, $references),
            self::allocationViews($current, $previous, $groups, $tree->lineages, $currentFigures->shareInputs, $previousFigures->shareInputs, $shares, $references),
            $references->for($current->asset),
            StaleValuationView::listFor($set->accounts, $currentContributions),
        );
    }

    /**
     * @param list<AccountGroup> $groups
     *
     * @return list<NetWorthContributionView>
     */
    private static function contributionViews(
        NetWorthContributionSet $set,
        NetWorth $netWorth,
        array $groups,
        NetWorthShares $shares,
        NetWorthAssetReferences $references,
    ): array {
        $labels = [];
        $colors = \App\Module\Accounts\Domain\AccountGroupColors::resolveAll($groups);
        foreach ($groups as $group) {
            $labels[$group->id] = $group->label;
        }

        $accounts = [];
        foreach ($set->accounts as $account) {
            $accounts[$account->id] = $account;
        }

        $views = [];
        foreach ($set->on($netWorth->asOf) as $contribution) {
            $account = $accounts[$contribution->accountId] ?? null;
            if (!$account instanceof Account) {
                continue;
            }

            $views[] = NetWorthContributionView::of(
                $account,
                $contribution,
                null === $contribution->primaryGroupId ? null : ($labels[$contribution->primaryGroupId] ?? null),
                $shares->account($contribution->accountId),
                $references->for($contribution->amount?->asset),
                null === $contribution->primaryGroupId ? null : ($colors[$contribution->primaryGroupId] ?? null),
            );
        }

        return $views;
    }

    /**
     * @param list<AccountGroup>      $groups
     * @param list<GroupLineage>      $lineages
     * @param list<AccountShareInput> $shareInputs
     * @param list<AccountShareInput> $previousShareInputs the same accounts on the compared day
     *
     * @return list<NetWorthAllocationView>
     */
    private static function allocationViews(
        NetWorth $netWorth,
        NetWorth $previous,
        array $groups,
        array $lineages,
        array $shareInputs,
        array $previousShareInputs,
        NetWorthShares $shares,
        NetWorthAssetReferences $references,
    ): array {
        // A group total built from a partial set would look authoritative, so
        // the roll-up is published only when the aggregate itself holds.
        $rollup = self::rollup($netWorth, $shareInputs, $lineages);
        $previousRollup = self::rollup($previous, $previousShareInputs, $lineages);
        $direct = self::rollup($netWorth, $shareInputs, $lineages, true);
        $previousDirect = self::rollup($previous, $previousShareInputs, $lineages, true);
        $parents = [];
        foreach ($groups as $candidate) {
            if (null !== $candidate->parentId && null === $candidate->archivedAt) {
                $parents[$candidate->parentId] = true;
            }
        }

        // Same reading order as the accounts page, so every panel lists groups alike.
        $colors = \App\Module\Accounts\Domain\AccountGroupColors::of($groups);
        $views = [];
        foreach (AccountGroupTree::depthFirst($groups) as $group) {
            if (null !== $group->archivedAt) {
                continue;
            }

            $views[] = NetWorthAllocationView::of(
                $group,
                $colors,
                $rollup[$group->id] ?? null,
                $netWorth->asset,
                $shares->group($group->id),
                $references->for($netWorth->asset),
                $previousRollup[$group->id] ?? null,
                $previous->asset,
                $references->for($previous->asset),
                $direct[$group->id] ?? null,
                $shares->directGroup($group->id),
                $previousDirect[$group->id] ?? null,
                isset($parents[$group->id]),
            );
        }

        return $views;
    }

    /**
     * @param list<AccountShareInput> $inputs
     * @param list<GroupLineage>      $lineages
     *
     * @return array<string, DecimalValue>
     */
    private static function rollup(NetWorth $day, array $inputs, array $lineages, bool $direct = false): array
    {
        $totals = $direct ? ExclusiveGroupRollup::directTotals(...) : ExclusiveGroupRollup::totals(...);

        return null === $day->total
            ? []
            : $totals(
                array_values(array_filter(
                    $inputs,
                    static fn (AccountShareInput $input): bool => $input->includeInNetWorth,
                )),
                $lineages,
            );
    }
}
