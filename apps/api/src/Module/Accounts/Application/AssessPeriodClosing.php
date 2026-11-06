<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosingCondition;
use App\Module\Accounts\Domain\PeriodClosingReason;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Which checks a month still fails. Closure is workspace-wide, so every
 * account open during the month is examined, not the one the caller happens to
 * be looking at.
 *
 * An account counts as reconciled for the month when its latest active
 * snapshot on or before the last day falls inside the month and is reconciled.
 * A month with no snapshot at all is not reconciled: silence is not a match.
 * A portfolio-mode account is reconciled when its latest snapshot of the month
 * is a CALCULATED one, the figure its valuation publishes; otherwise it raises
 * its own condition rather than counting as an unreconciled balance.
 */
final readonly class AssessPeriodClosing
{
    public const int MAX_ACCOUNTS = 500;

    public function __construct(
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private AccountGroupRepository $groups,
        private PeriodClosingFacts $facts,
        private WorkspaceTimezoneReader $timezones,
    ) {
    }

    /** @return list<PeriodClosingBlocker> only the conditions that fail, in a stable order */
    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month): array
    {
        $first = $month->firstDay();
        $last = $month->lastDay();
        $workspaceTimezone = new \DateTimeZone($this->timezones->timezone($workspace));
        $accounts = $this->accounts->listOpenDuring($workspace, $first, $last, $workspaceTimezone, self::MAX_ACCOUNTS + 1);
        if (count($accounts) > self::MAX_ACCOUNTS) {
            throw new InvalidPeriodClosureInput('The workspace holds more accounts than a closing examines at once.');
        }

        $latest = $this->snapshots->findLatestForAccounts($workspace, array_map(static fn ($account): string => $account->id, $accounts), $last);
        $unreconciled = [];
        $missingValuation = [];
        $toCompare = [];
        foreach ($accounts as $account) {
            $snapshot = $latest[$account->id] ?? null;
            $inMonth = null !== $snapshot && $snapshot->asOf >= $first;
            if (AccountValuationMode::PORTFOLIO === $account->valuationMode) {
                // Valued through its portfolio valuations: only a published CALCULATED figure of the month counts,
                // and a valuation is not a balance to compare with movements.
                if (!$inMonth) {
                    $missingValuation[$account->id] = self::item($account, PeriodClosingReason::NO_MONTH_END_VALUATION, null, null);
                } elseif (BalanceSnapshotSource::CALCULATED !== $snapshot->source) {
                    $missingValuation[$account->id] = self::item($account, PeriodClosingReason::VALUATION_NOT_CALCULATED, $snapshot->asOf, null);
                }

                continue;
            }
            if (!$inMonth) {
                $unreconciled[$account->id] = self::item($account, PeriodClosingReason::NO_BALANCE_IN_MONTH, null, null);
            } elseif (ReconciliationStatus::RECONCILED !== $snapshot->reconciliationStatus) {
                $unreconciled[$account->id] = self::item($account, PeriodClosingReason::BALANCE_NOT_RECONCILED, $snapshot->asOf, null);
            }
            if ($inMonth && ReconciliationStatus::UNRECONCILED === $snapshot->reconciliationStatus) {
                $toCompare[] = $snapshot;
            }
        }

        $gaps = [] === $toCompare ? [] : $this->facts->unexplainedDiscrepancies($workspace, $toCompare, $first);
        $discrepancies = [];
        foreach ($toCompare as $snapshot) {
            $gap = $gaps[$snapshot->accountId] ?? null;
            if (null !== $gap) {
                $account = $this->byId($accounts, $snapshot->accountId);
                $discrepancies[$snapshot->accountId] = self::item($account, PeriodClosingReason::DISCREPANCY, $snapshot->asOf, new AssetAmount($gap, $account->assetCode));
            }
        }

        $items = [
            PeriodClosingCondition::UNRECONCILED_ACCOUNT->value => $unreconciled,
            PeriodClosingCondition::UNEXPLAINED_DISCREPANCY->value => $discrepancies,
            PeriodClosingCondition::MISSING_PORTFOLIO_VALUATION->value => $missingValuation,
        ];
        // The order is only worth a read when something is listed: a month with nothing to fix never depends on it.
        $order = [] === $unreconciled && [] === $discrepancies && [] === $missingValuation
            ? []
            : array_flip($this->overviewOrder($workspace, $accounts));
        $items = array_map(static fn (array $listed): array => self::sorted($listed, $order), $items);
        $pending = $this->facts->pendingTransactionCount($workspace, $first, $last);

        $blockers = [];
        foreach (PeriodClosingCondition::cases() as $condition) {
            if (PeriodClosingCondition::PENDING_TRANSACTIONS === $condition) {
                if ($pending > 0) {
                    $blockers[] = new PeriodClosingBlocker($condition, $pending);
                }

                continue;
            }
            $listed = $items[$condition->value];
            if ([] !== $listed) {
                $blockers[] = new PeriodClosingBlocker($condition, count($listed), $listed);
            }
        }

        return $blockers;
    }

    /**
     * @param list<Account> $accounts
     *
     * @return list<string> account identifiers as the accounts overview reads them
     */
    private function overviewOrder(WorkspaceScope $workspace, array $accounts): array
    {
        $groups = $this->groups->list($workspace, true, ReadNetWorth::MAX_GROUPS + 1, 0);
        // Too many groups to rank them: fall back to the saved order of the accounts rather than refuse the closing.
        if (count($groups) > ReadNetWorth::MAX_GROUPS) {
            $groups = [];
        }

        return $this->accounts->idsInOverviewOrder(
            $workspace,
            array_map(static fn (AccountGroup $group): string => $group->id, AccountGroupTree::depthFirst($groups)),
            array_map(static fn (Account $account): string => $account->id, $accounts),
        );
    }

    /** @param list<Account> $accounts */
    private function byId(array $accounts, string $id): Account
    {
        foreach ($accounts as $account) {
            if ($account->id === $id) {
                return $account;
            }
        }

        throw new \LogicException('A compared snapshot always belongs to an examined account.');
    }

    /**
     * @param array<string, PeriodClosingBlockerItem> $items
     * @param array<string, int>                      $order position by account identifier
     *
     * @return list<PeriodClosingBlockerItem>
     */
    private static function sorted(array $items, array $order): array
    {
        $list = array_values($items);
        usort($list, static fn (PeriodClosingBlockerItem $left, PeriodClosingBlockerItem $right): int => ($order[$left->accountId] ?? PHP_INT_MAX) <=> ($order[$right->accountId] ?? PHP_INT_MAX));

        return $list;
    }

    private static function item(Account $account, PeriodClosingReason $reason, ?\DateTimeImmutable $snapshotAsOf, ?AssetAmount $discrepancy): PeriodClosingBlockerItem
    {
        return new PeriodClosingBlockerItem($account->id, $account->label, $account->kind, $account->valuationMode, $reason, $snapshotAsOf, $discrepancy);
    }
}
