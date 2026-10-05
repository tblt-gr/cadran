<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\AccountView;
use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Valuation\DueMonthWindow;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Month-end valuations still owed, computed from source data on every read and
 * never stored. A month is due when its last day has come in the workspace
 * calendar, it is not closed and no active COMPLETE valuation is dated in it.
 */
final readonly class PortfolioDueMonths
{
    public function __construct(
        private ClockInterface $clock,
        private WorkspaceTimezoneReader $timezones,
        private AssertPeriodOpen $periods,
        private ValuationRepository $valuations,
    ) {
    }

    /** The calendar day of the workspace, from its own time zone and never the server's. */
    public function today(WorkspaceScope $workspace): \DateTimeImmutable
    {
        return new \DateTimeImmutable(
            $this->clock->now()->setTimezone(new \DateTimeZone($this->timezones->timezone($workspace)))->format('Y-m-d'),
            new \DateTimeZone('UTC'),
        );
    }

    /**
     * @param list<AccountView> $accounts
     *
     * @return array<string, list<string>> `YYYY-MM` keys, oldest first, per account id; every eligible account has an entry
     */
    public function __invoke(WorkspaceScope $workspace, array $accounts, \DateTimeImmutable $today): array
    {
        $utc = new \DateTimeZone('UTC');
        $candidates = [];
        foreach ($accounts as $account) {
            if ('ARCHIVED' === $account->status || !in_array($account->kind, ['PORTFOLIO', 'INSURANCE_CONTRACT'], true) || 'PORTFOLIO' !== $account->valuationMode) {
                continue;
            }
            $months = DueMonthWindow::candidates($today, new \DateTimeImmutable($account->openedOn, $utc), null === $account->closedOn ? null : new \DateTimeImmutable($account->closedOn, $utc));
            if ([] !== $months) {
                $candidates[$account->id] = $months;
            }
        }
        $due = array_fill_keys(array_map(static fn (AccountView $account): string => $account->id, $accounts), []);
        if ([] === $candidates) {
            return $due;
        }

        $all = array_merge(...array_values($candidates));
        usort($all, static fn (CalendarMonth $a, CalendarMonth $b): int => $a->key() <=> $b->key());
        $closed = $this->periods->closedMonthKeys($workspace, array_map(static fn (CalendarMonth $month): \DateTimeImmutable => $month->firstDay(), self::distinct($all)));
        $valued = $this->valuations->completeMonths($workspace, array_keys($candidates), $all[0]->firstDay(), $all[count($all) - 1]->lastDay());
        foreach ($candidates as $accountId => $months) {
            $done = array_flip($valued[$accountId] ?? []);
            foreach ($months as $month) {
                if (!isset($closed[$month->key()]) && !isset($done[$month->key()])) {
                    $due[$accountId][] = $month->key();
                }
            }
        }

        return $due;
    }

    /**
     * @param list<CalendarMonth> $months
     *
     * @return list<CalendarMonth>
     */
    private static function distinct(array $months): array
    {
        $byKey = [];
        foreach ($months as $month) {
            $byKey[$month->key()] = $month;
        }

        return array_values($byKey);
    }
}
