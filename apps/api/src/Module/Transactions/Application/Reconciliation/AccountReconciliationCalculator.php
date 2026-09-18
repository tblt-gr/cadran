<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\PresentTransaction;
use App\Module\Transactions\Domain\Reconciliation\AccountBalanceComparison;
use App\Module\Transactions\Domain\TransactionRepository;

/**
 * Compares one observed closing balance to the movements of its period. The
 * period ends on the snapshot's day and starts on the day the caller names;
 * the opening figure is the latest active snapshot the day before.
 */
final readonly class AccountReconciliationCalculator
{
    public const int MAX_PERIOD_DAYS = 366;
    public const int PENDING_LIST_LIMIT = 100;

    public function __construct(
        private AccountBalanceSnapshotRepository $snapshots,
        private TransactionRepository $transactions,
        private PresentTransaction $present,
    ) {
    }

    public function periodStart(string $periodStart, AccountBalanceSnapshot $closing): \DateTimeImmutable
    {
        try {
            $start = BusinessDay::fromIsoDate($periodStart)->date;
        } catch (\Throwable $exception) {
            throw new InvalidAccountReconciliation('The period start must be an ISO 8601 calendar day.', previous: $exception);
        }
        if ($start > $closing->asOf) {
            throw new InvalidAccountReconciliation('The period cannot start after the closing balance day.');
        }
        if ($start->diff($closing->asOf)->days > self::MAX_PERIOD_DAYS) {
            throw new InvalidAccountReconciliation('The period is longer than the supported window.');
        }

        return $start;
    }

    /**
     * The window actually summed starts the day after the opening snapshot, not
     * on the requested start: a movement booked between the two would otherwise
     * be in neither figure and show up as an invented discrepancy. Without an
     * opening the requested start stands, and the comparison is not calculable.
     */
    public function compare(WorkspaceScope $workspace, AccountBalanceSnapshot $closing, \DateTimeImmutable $start): AccountReconciliationFigures
    {
        $accountId = $closing->accountId;
        $opening = $this->snapshots->findLatestForAccounts($workspace, [$accountId], $start->modify('-1 day'))[$accountId] ?? null;
        $latest = $this->snapshots->findLatestForAccounts($workspace, [$accountId], $closing->asOf)[$accountId] ?? null;
        $windowStart = null === $opening ? $start : $opening->asOf->modify('+1 day');

        return new AccountReconciliationFigures(
            AccountBalanceComparison::of(
                $closing,
                $closing->isActive() && null !== $latest && $latest->id === $closing->id,
                $opening,
                $this->transactions->sumBookedMovements($workspace, $accountId, $windowStart, $closing->asOf),
            ),
            $windowStart,
        );
    }

    public function view(
        WorkspaceScope $workspace,
        AccountBalanceSnapshot $closing,
        AccountReconciliationFigures $figures,
    ): AccountReconciliationView {
        $comparison = $figures->comparison;
        $start = $figures->windowStart;
        $asset = $comparison->asset;
        $amount = static fn (?DecimalValue $value): ?array => null === $value
            ? null
            : ['value' => $value->toString(), 'assetCode' => $asset->toString()];
        $resolutions = ReconciliationStatus::RECONCILED === $closing->reconciliationStatus
            ? []
            : array_map(static fn ($resolution): string => $resolution->value, $comparison->availableResolutions());

        return new AccountReconciliationView(
            accountId: $closing->accountId,
            snapshotId: $closing->id,
            snapshotVersion: $closing->version,
            reconciliationStatus: $closing->reconciliationStatus->value,
            periodStart: $start->format('Y-m-d'),
            periodEnd: $closing->asOf->format('Y-m-d'),
            opening: $amount($comparison->opening),
            movements: $amount($comparison->movements),
            closing: ['value' => $comparison->closing->toString(), 'assetCode' => $asset->toString()],
            discrepancy: $amount($comparison->discrepancy),
            reason: $comparison->reason?->value,
            pendingCount: $this->transactions->countPendingInPeriod($workspace, $closing->accountId, $start, $closing->asOf),
            pendingTransactions: $this->present->many($this->transactions->listPendingInPeriod(
                $workspace, $closing->accountId, $start, $closing->asOf, self::PENDING_LIST_LIMIT,
            )),
            availableResolutions: $resolutions,
        );
    }
}
