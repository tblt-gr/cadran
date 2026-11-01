<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\ExclusiveGroupRollup;
use App\Module\Accounts\Domain\NetWorthCalculator;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\NetWorthShareCalculator;
use App\Module\Foundation\Domain\WorkspaceScope;
use Symfony\Component\Clock\ClockInterface;

/**
 * Weighs groups and accounts against the eligible net worth of one day.
 *
 * It reuses the net-worth contributions, the exclusive roll-up and the share
 * calculator rather than summing a page of accounts: a lifecycle filter hides
 * rows, it never moves a total or a percentage.
 */
final readonly class AccountOverviewFigures
{
    public function __construct(
        private ResolveNetWorthContributions $contributions,
        private AccountGroupRepository $groups,
        private ClockInterface $clock,
    ) {
    }

    public function on(WorkspaceScope $workspace, ?string $asOf): OverviewFigures
    {
        try {
            $day = NetWorthDay::parse($asOf, $this->clock);
        } catch (InvalidNetWorthQuery $exception) {
            throw new InvalidAccountOverviewQuery($exception->getMessage(), previous: $exception);
        }

        $contributionSet = $this->contributions->on($workspace, [$day]);
        $contributions = $contributionSet->on($day);
        $netWorth = NetWorthCalculator::compute($contributions, $day);

        $groups = $this->groups->list($workspace, true, ReadNetWorth::MAX_GROUPS + 1, 0);
        if (count($groups) > ReadNetWorth::MAX_GROUPS) {
            throw new NetWorthScopeTooLarge('This workspace holds more groups than one overview reads.');
        }

        $lineages = AccountGroupTree::lineages($groups);
        $inputs = array_map(
            static fn (NetWorthContribution $contribution): AccountShareInput => $contribution->share(),
            $contributions,
        );
        $included = array_values(array_filter($inputs, static fn (AccountShareInput $input): bool => $input->includeInNetWorth));
        $calculable = null !== $netWorth->total;

        return new OverviewFigures(
            day: $day,
            netWorth: $netWorth,
            groups: $groups,
            lineages: $lineages,
            shares: NetWorthShareCalculator::compute($inputs, $lineages),
            rollup: $calculable ? ExclusiveGroupRollup::totals($included, $lineages) : [],
            direct: $calculable ? ExclusiveGroupRollup::directTotals($included, $lineages) : [],
            ungroupedTotal: $calculable ? ExclusiveGroupRollup::ungroupedTotal($included) : null,
            contributions: $contributions,
            accounts: $contributionSet->accounts,
        );
    }
}
