<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\BulkCategorizationFacts;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransferRepository;

/** Reads, once per bulk run, what surrounds each locked transaction. */
final readonly class ReadBulkEditFacts
{
    public function __construct(
        private RefundRepository $refunds,
        private TransferRepository $transfers,
        private AssertPeriodOpen $assertPeriodOpen,
    ) {
    }

    /**
     * @param array<string, Transaction> $rows by identifier
     *
     * @return array<string, BulkCategorizationFacts>
     */
    public function __invoke(WorkspaceScope $workspace, array $rows): array
    {
        $ids = array_keys($rows);
        $legs = $this->transfers->markersForLegs($workspace, $ids);
        $refundOf = $this->refunds->originalsByRefundTransactionId($workspace, $ids);
        $refunded = $this->refunds->liveRefundedAmounts($workspace, $ids);
        $closedMonths = [];
        foreach ($rows as $row) {
            $month = $row->bookedOn->format('Y-m');
            if (isset($closedMonths[$month])) {
                continue;
            }
            try {
                ($this->assertPeriodOpen)($workspace, $row->bookedOn);
                $closedMonths[$month] = false;
            } catch (PeriodClosed) {
                $closedMonths[$month] = true;
            }
        }

        $facts = [];
        foreach ($rows as $id => $row) {
            $facts[$id] = new BulkCategorizationFacts(
                transferLeg: isset($legs[$id]),
                refund: isset($refundOf[$id]),
                liveRefund: isset($refunded[$id]),
                periodClosed: $closedMonths[$row->bookedOn->format('Y-m')],
            );
        }

        return $facts;
    }
}
