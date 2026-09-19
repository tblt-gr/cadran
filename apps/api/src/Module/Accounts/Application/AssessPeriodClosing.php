<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosingCondition;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Which checks a month still fails. Closure is workspace-wide, so every
 * account open during the month is examined, not the one the caller happens to
 * be looking at.
 *
 * An account counts as reconciled for the month when its latest active
 * snapshot on or before the last day falls inside the month and is reconciled.
 * A month with no snapshot at all is not reconciled: silence is not a match.
 */
final readonly class AssessPeriodClosing
{
    public const int MAX_ACCOUNTS = 500;

    public function __construct(
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private PeriodClosingFacts $facts,
    ) {
    }

    /** @return list<PeriodClosingBlocker> only the conditions that fail, in a stable order */
    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month): array
    {
        $first = $month->firstDay();
        $last = $month->lastDay();
        $accounts = $this->accounts->listOpenDuring($workspace, $first, $last, self::MAX_ACCOUNTS + 1);
        if (count($accounts) > self::MAX_ACCOUNTS) {
            throw new InvalidPeriodClosureInput('The workspace holds more accounts than a closing examines at once.');
        }

        $latest = $this->snapshots->findLatestForAccounts($workspace, array_map(static fn ($account): string => $account->id, $accounts), $last);
        $unreconciled = 0;
        $toCompare = [];
        foreach ($accounts as $account) {
            $snapshot = $latest[$account->id] ?? null;
            $inMonth = null !== $snapshot && $snapshot->asOf >= $first;
            if (!$inMonth || ReconciliationStatus::RECONCILED !== $snapshot->reconciliationStatus) {
                ++$unreconciled;
            }
            if ($inMonth && ReconciliationStatus::UNRECONCILED === $snapshot->reconciliationStatus) {
                $toCompare[] = $snapshot;
            }
        }

        $counts = [
            PeriodClosingCondition::UNRECONCILED_ACCOUNT->value => $unreconciled,
            PeriodClosingCondition::UNEXPLAINED_DISCREPANCY->value => self::discrepancies($this->facts, $workspace, $toCompare, $first),
            PeriodClosingCondition::PENDING_TRANSACTIONS->value => $this->facts->pendingTransactionCount($workspace, $first, $last),
        ];

        $blockers = [];
        foreach (PeriodClosingCondition::cases() as $condition) {
            if ($counts[$condition->value] > 0) {
                $blockers[] = new PeriodClosingBlocker($condition, $counts[$condition->value]);
            }
        }

        return $blockers;
    }

    /** @param list<AccountBalanceSnapshot> $closings */
    private static function discrepancies(PeriodClosingFacts $facts, WorkspaceScope $workspace, array $closings, \DateTimeImmutable $from): int
    {
        return [] === $closings ? 0 : $facts->unexplainedDiscrepancyCount($workspace, $closings, $from);
    }
}
