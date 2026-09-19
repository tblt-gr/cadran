<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountBalanceSnapshots;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\ValuationQuality;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Turns the accounts of one workspace into dated net-worth contributions.
 *
 * Both the headline aggregate and the history curve read from here, so a
 * point on the curve and the figure above it can never be built from
 * different rules.
 */
final readonly class ResolveNetWorthContributions
{
    /**
     * A personal workspace holds tens of accounts. The cap exists so a
     * pathological or hostile workspace cannot turn one GET into an unbounded
     * scan; exceeding it is refused rather than silently truncated.
     */
    public const int MAX_ACCOUNTS = 500;

    public function __construct(
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private WorkspaceTimezoneReader $timezones,
    ) {
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     *
     * @throws NetWorthScopeTooLarge when the workspace exceeds {@see MAX_ACCOUNTS}
     */
    public function on(WorkspaceScope $workspace, array $dates): NetWorthContributionSet
    {
        if ([] === $dates) {
            throw new \InvalidArgumentException('A net-worth contribution read needs at least one date.');
        }
        $orderedDates = $dates;
        usort($orderedDates, static fn (\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);
        $workspaceTimezone = new \DateTimeZone($this->timezones->timezone($workspace));
        $accounts = $this->accounts->listForNetWorthDuring(
            $workspace,
            $orderedDates[0],
            $orderedDates[count($orderedDates) - 1],
            $workspaceTimezone,
            self::MAX_ACCOUNTS + 1,
        );
        if (count($accounts) > self::MAX_ACCOUNTS) {
            throw new NetWorthScopeTooLarge('This workspace holds more accounts than one net-worth aggregate reads.');
        }

        $latest = $this->snapshots->findLatestForAccountsOnDates(
            $workspace,
            array_map(static fn (Account $account): string => $account->id, $accounts),
            $dates,
        );

        $byDate = [];
        foreach ($dates as $date) {
            $day = $date->format('Y-m-d');
            $byDate[$day] = array_map(
                static fn (Account $account): NetWorthContribution => self::contribution(
                    $account,
                    $date,
                    $latest[$day][$account->id] ?? null,
                    $workspaceTimezone,
                ),
                $accounts,
            );
        }

        return new NetWorthContributionSet($accounts, $byDate);
    }

    private static function contribution(
        Account $account,
        \DateTimeImmutable $on,
        ?AccountBalanceSnapshot $snapshot,
        \DateTimeZone $workspaceTimezone,
    ): NetWorthContribution {
        $valuation = AccountValuation::of(
            new AccountBalanceSnapshots(null === $snapshot ? [] : [$snapshot]),
            $on,
        );
        $eligible = $account->isActiveOn($on, $workspaceTimezone);

        return new NetWorthContribution(
            accountId: $account->id,
            netWorthSign: $account->netWorthSign(),
            primaryGroupId: $account->primaryGroupId,
            tagGroupIds: $account->tagGroupIds,
            amount: $valuation->amount,
            quality: $valuation->quality,
            ageDays: $valuation->ageDays,
            valuedOn: ValuationQuality::MISSING === $valuation->quality ? null : $valuation->asOf,
            eligible: $eligible,
        );
    }
}
