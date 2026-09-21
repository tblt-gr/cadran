<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\ExclusiveGroupRollup;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Accounts\Domain\NetWorth;
use App\Module\Accounts\Domain\NetWorthCalculator;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\NetWorthDelta;
use App\Module\Accounts\Domain\NetWorthShareCalculator;
use App\Module\Accounts\Domain\NetWorthShares;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reference\Application\AssetCatalog;
use Symfony\Component\Clock\ClockInterface;

/**
 * The explainable net worth of the calling workspace on one business day.
 *
 * Everything a reader needs to disagree with the figure travels with it: the
 * contributing accounts, the exclusive allocation, the freshness of each
 * valuation and the movement since a compared day.
 */
final readonly class ReadNetWorth
{
    /**
     * The exclusive tree is at most eight levels deep and a personal workspace
     * keeps tens of buckets. The cap only stops an aggregate from turning into
     * an unbounded scan.
     */
    public const int MAX_GROUPS = 500;

    public function __construct(
        private CallerWorkspace $caller,
        private ResolveNetWorthContributions $contributions,
        private AccountGroupRepository $groups,
        private AssetCatalog $assets,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?string $asOf = null, ?string $comparedTo = null): NetWorthView
    {
        $requestedOn = NetWorthDay::parse($asOf, $this->clock);
        $comparedOn = null === $comparedTo || '' === $comparedTo
            ? NetWorthDay::previousMonth($requestedOn)
            : NetWorthDay::parse($comparedTo, $this->clock);

        if ($comparedOn >= $requestedOn) {
            throw new InvalidNetWorthQuery('The compared day must precede the requested day.');
        }

        $workspace = $this->caller->resolve();
        $set = $this->contributions->on($workspace, [$requestedOn, $comparedOn]);
        $currentContributions = $set->on($requestedOn);
        $previousContributions = $set->on($comparedOn);
        $current = NetWorthCalculator::compute($currentContributions, $requestedOn);
        $previous = NetWorthCalculator::compute($previousContributions, $comparedOn);

        // Archived buckets still carry the lineage of the accounts that point
        // at them, so they are read for the roll-up and dropped from the
        // published allocation rather than being missing from both.
        $groups = $this->groups->list($workspace, true, self::MAX_GROUPS + 1, 0);
        if (count($groups) > self::MAX_GROUPS) {
            // Truncating would drop the deepest buckets and publish the
            // remaining ones as if they held everything.
            throw new NetWorthScopeTooLarge('This workspace holds more groups than one net-worth allocation reads.');
        }

        $lineages = AccountGroupTree::lineages($groups);
        $shareInputs = array_map(
            static fn (NetWorthContribution $contribution): AccountShareInput => $contribution->share(),
            $currentContributions,
        );
        $shares = NetWorthShareCalculator::compute($shareInputs, $lineages);
        $references = new NetWorthAssetReferences($this->assets);

        return NetWorthView::of(
            $current,
            NetWorthDeltaView::fromDelta(
                NetWorthDelta::between($current, $previous),
                $references,
                self::sourceAccountIds($previousContributions),
                self::sourceAccountIds($currentContributions),
            ),
            self::contributionViews($set, $current, $groups, $shares, $references),
            self::allocationViews($current, $groups, $lineages, $shareInputs, $shares, $references),
            $references->for($current->asset),
        );
    }

    /**
     * @param list<NetWorthContribution> $contributions
     *
     * @return list<string>
     */
    private static function sourceAccountIds(array $contributions): array
    {
        $ids = array_map(
            static fn (NetWorthContribution $contribution): string => $contribution->accountId,
            array_filter($contributions, static fn (NetWorthContribution $contribution): bool => $contribution->eligible),
        );
        sort($ids, SORT_STRING);

        return $ids;
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
            );
        }

        return $views;
    }

    /**
     * @param list<AccountGroup>      $groups
     * @param list<GroupLineage>      $lineages
     * @param list<AccountShareInput> $shareInputs
     *
     * @return list<NetWorthAllocationView>
     */
    private static function allocationViews(
        NetWorth $netWorth,
        array $groups,
        array $lineages,
        array $shareInputs,
        NetWorthShares $shares,
        NetWorthAssetReferences $references,
    ): array {
        // A group total built from a partial set would look authoritative, so
        // the roll-up is published only when the aggregate itself holds.
        $rollup = null === $netWorth->total
            ? []
            : ExclusiveGroupRollup::totals(
                array_values(array_filter(
                    $shareInputs,
                    static fn (AccountShareInput $input): bool => $input->includeInNetWorth,
                )),
                $lineages,
            );

        $views = [];
        foreach ($groups as $group) {
            if (null !== $group->archivedAt) {
                continue;
            }

            $views[] = NetWorthAllocationView::of(
                $group,
                $rollup[$group->id] ?? null,
                $netWorth->asset,
                $shares->group($group->id),
                $references->for($netWorth->asset),
            );
        }

        return $views;
    }
}
