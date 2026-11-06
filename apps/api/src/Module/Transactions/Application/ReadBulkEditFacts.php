<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\BulkCategorizationFacts;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationRepository;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransferRepository;

/** Reads, once per bulk run, what surrounds each locked transaction. */
final readonly class ReadBulkEditFacts
{
    public function __construct(
        private RefundRepository $refunds,
        private TransferRepository $transfers,
        private AssertPeriodOpen $assertPeriodOpen,
        private ReconciliationRepository $reconciliations,
        private PortfolioLinkedTransactions $portfolioLinks,
        private TransactionRepository $transactions,
    ) {
    }

    /**
     * @param array<string, Transaction> $rows        by identifier
     * @param bool                       $moving      whether the run changes account or date, which reads the extra facts
     * @param ?\DateTimeImmutable        $newBookedOn the day a date change moves every row to
     *
     * @return array<string, BulkCategorizationFacts>
     */
    public function __invoke(WorkspaceScope $workspace, array $rows, bool $moving = false, ?\DateTimeImmutable $newBookedOn = null): array
    {
        $ids = array_keys($rows);
        $legs = $this->transfers->markersForLegs($workspace, $ids);
        $refundOf = $this->refunds->originalsByRefundTransactionId($workspace, $ids);
        $refunded = $this->refunds->liveRefundedAmounts($workspace, $ids);
        $closedMonths = [];
        foreach ($rows as $row) {
            $month = $row->bookedOn->format('Y-m');
            $closedMonths[$month] ??= $this->isClosed($workspace, $row->bookedOn);
        }

        $linked = $moving ? array_flip($this->portfolioLinks->among($workspace, $ids)) : [];
        $matched = $moving ? array_flip($this->reconciliations->matchedAmong($workspace, $ids)) : [];
        $targetClosed = null !== $newBookedOn && $this->isClosed($workspace, $newBookedOn);
        $originalDays = null === $newBookedOn ? [] : $this->originalDays($workspace, $refundOf);

        $facts = [];
        foreach ($rows as $id => $row) {
            $facts[$id] = new BulkCategorizationFacts(
                transferLeg: isset($legs[$id]),
                refund: isset($refundOf[$id]),
                liveRefund: isset($refunded[$id]),
                periodClosed: $closedMonths[$row->bookedOn->format('Y-m')],
                portfolioLinked: isset($linked[$id]),
                reconciled: isset($matched[$id]),
                targetPeriodClosed: $targetClosed && $newBookedOn != $row->bookedOn,
                originalBookedOn: isset($refundOf[$id]) ? ($originalDays[$refundOf[$id]['originalId']] ?? null) : null,
            );
        }

        return $facts;
    }

    private function isClosed(WorkspaceScope $workspace, \DateTimeImmutable $day): bool
    {
        try {
            ($this->assertPeriodOpen)($workspace, $day);

            return false;
        } catch (PeriodClosed) {
            return true;
        }
    }

    /**
     * @param array<string, array{originalId: string, originalLabel: string}> $refundOf
     *
     * @return array<string, \DateTimeImmutable> booked day per original identifier
     */
    private function originalDays(WorkspaceScope $workspace, array $refundOf): array
    {
        $originalIds = array_values(array_unique(array_map(static fn (array $original): string => $original['originalId'], $refundOf)));
        $days = [];
        foreach ([] === $originalIds ? [] : $this->transactions->findMany($workspace, $originalIds) as $original) {
            $days[$original->id] = $original->bookedOn;
        }

        return $days;
    }
}
