<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountGroupColors;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Account values and reconciliation facts for one bounded month. */
final readonly class ReadMonthlyAccountFacts
{
    public const int MAX_ACCOUNTS = 500;

    public function __construct(
        private AccountRepository $accounts,
        private ResolveAccountValuations $valuations,
        private WorkspaceTimezoneReader $timezones,
        private AccountGroupRepository $groups,
    ) {
    }

    /**
     * @param ?\DateTimeImmutable $effectiveEnd    the day the month is read up to, when it is not the
     *                                             calendar end: a month still running stops on the day
     *                                             the workspace is living in, so a valuation the reader
     *                                             dated later is never published as already applying
     * @param bool                $withGroupColors reads the workspace groups to publish each account's group color;
     *                                             off by default so readers that never draw it pay no group read
     */
    public function __invoke(
        WorkspaceScope $workspace,
        CalendarMonth $month,
        ?\DateTimeImmutable $effectiveEnd = null,
        bool $withGroupColors = false,
    ): MonthlyAccountFacts {
        $first = $month->firstDay();
        $last = $effectiveEnd ?? $month->lastDay();
        if ($last < $first || $last > $month->lastDay()) {
            throw new \InvalidArgumentException('A monthly read ends inside the month it reads.');
        }
        $workspaceTimezone = new \DateTimeZone($this->timezones->timezone($workspace));
        $accounts = $this->accounts->listOpenDuring($workspace, $first, $last, $workspaceTimezone, self::MAX_ACCOUNTS + 1);
        if (count($accounts) > self::MAX_ACCOUNTS) {
            throw new NetWorthScopeTooLarge('A monthly projection reads at most 500 accounts.');
        }
        $colors = $withGroupColors ? $this->colors($workspace) : null;
        $latest = $this->valuations->forAccounts($workspace, $accounts, [$first, $last]);

        $facts = array_map(function (Account $account) use ($latest, $first, $last, $workspaceTimezone, $colors): MonthlyAccountFact {
            $beginning = $latest[$first->format('Y-m-d')][$account->id];
            $end = $latest[$last->format('Y-m-d')][$account->id];
            $reconciled = null !== $end->snapshot
                && $end->snapshot->asOf >= $first
                && ReconciliationStatus::RECONCILED === $end->snapshot->reconciliationStatus;

            return new MonthlyAccountFact(
                $account->id,
                $account->label,
                $account->assetCode->toString(),
                $account->kind->value,
                $account->kind->isSavingsDestination(),
                self::valuation($account, $beginning, $first, $workspaceTimezone),
                self::valuation($account, $end, $last, $workspaceTimezone),
                $reconciled ? ReconciliationStatus::RECONCILED->value : ReconciliationStatus::UNRECONCILED->value,
                $colors?->forId($account->primaryGroupId),
            );
        }, $accounts);

        $status = match (true) {
            [] === $facts => 'NO_ACCOUNTS',
            [] === array_filter($facts, static fn (MonthlyAccountFact $fact): bool => ReconciliationStatus::RECONCILED->value !== $fact->reconciliationStatus) => ReconciliationStatus::RECONCILED->value,
            default => ReconciliationStatus::UNRECONCILED->value,
        };

        return new MonthlyAccountFacts($facts, $status);
    }

    private function colors(WorkspaceScope $workspace): AccountGroupColors
    {
        $groups = $this->groups->list($workspace, true, ReadNetWorth::MAX_GROUPS + 1, 0);
        if (count($groups) > ReadNetWorth::MAX_GROUPS) {
            // A truncated tree would silently drop the colors of the deepest groups.
            throw new NetWorthScopeTooLarge('This workspace holds more groups than one monthly read colors.');
        }

        return AccountGroupColors::of($groups);
    }

    private static function valuation(
        Account $account,
        AccountValuation $valuation,
        \DateTimeImmutable $on,
        \DateTimeZone $workspaceTimezone,
    ): MonthlyAccountValuationFact {
        if (!$account->isActiveOn($on, $workspaceTimezone)) {
            return new MonthlyAccountValuationFact(null, 'MISSING', null, null);
        }

        return new MonthlyAccountValuationFact(
            $valuation->amount,
            $valuation->quality->value,
            $valuation->ageDays,
            null === $valuation->amount ? null : $valuation->asOf->format('Y-m-d'),
        );
    }
}
