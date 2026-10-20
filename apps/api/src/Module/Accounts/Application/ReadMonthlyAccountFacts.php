<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
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
    ) {
    }

    /**
     * @param ?\DateTimeImmutable $effectiveEnd the day the month is read up to, when it is not the
     *                                          calendar end: a month still running stops on the day
     *                                          the workspace is living in, so a valuation the reader
     *                                          dated later is never published as already applying
     */
    public function __invoke(
        WorkspaceScope $workspace,
        CalendarMonth $month,
        ?\DateTimeImmutable $effectiveEnd = null,
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
        $latest = $this->valuations->forAccounts($workspace, $accounts, [$first, $last]);

        $facts = array_map(function (Account $account) use ($latest, $first, $last, $workspaceTimezone): MonthlyAccountFact {
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
            );
        }, $accounts);

        $status = match (true) {
            [] === $facts => 'NO_ACCOUNTS',
            [] === array_filter($facts, static fn (MonthlyAccountFact $fact): bool => ReconciliationStatus::RECONCILED->value !== $fact->reconciliationStatus) => ReconciliationStatus::RECONCILED->value,
            default => ReconciliationStatus::UNRECONCILED->value,
        };

        return new MonthlyAccountFacts($facts, $status);
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
