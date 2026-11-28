<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\ExclusiveGroupRollup;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;

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
        private LoadGroupTree $groupTree,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function on(WorkspaceScope $workspace, ?string $asOf): OverviewFigures
    {
        try {
            $day = NetWorthDay::parse($asOf, $this->calendar->today());
        } catch (InvalidNetWorthQuery $exception) {
            throw new InvalidAccountOverviewQuery($exception->getMessage(), previous: $exception);
        }

        $contributionSet = $this->contributions->on($workspace, [$day]);
        $contributions = $contributionSet->on($day);
        $tree = ($this->groupTree)($workspace);
        $figures = NetWorthDayFigures::of($contributions, $day, $tree->lineages);
        $netWorth = $figures->netWorth;
        $included = array_values(array_filter($figures->shareInputs, static fn (AccountShareInput $input): bool => $input->includeInNetWorth));
        $calculable = null !== $netWorth->total;

        return new OverviewFigures(
            day: $day,
            netWorth: $netWorth,
            groups: $tree->groups,
            lineages: $tree->lineages,
            shares: $figures->shares(),
            rollup: $calculable ? ExclusiveGroupRollup::totals($included, $tree->lineages) : [],
            direct: $calculable ? ExclusiveGroupRollup::directTotals($included, $tree->lineages) : [],
            ungroupedTotal: $calculable ? ExclusiveGroupRollup::ungroupedTotal($included) : null,
            contributions: $contributions,
            accounts: $contributionSet->accounts,
        );
    }
}
