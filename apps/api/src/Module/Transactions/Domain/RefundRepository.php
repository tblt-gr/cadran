<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

interface RefundRepository
{
    public function add(TransactionRefund $refund): void;

    public function findByRefundTransactionId(WorkspaceScope $workspace, string $refundTransactionId): ?TransactionRefund;

    /** @return list<TransactionRefund> */
    public function findByOriginalTransactionId(WorkspaceScope $workspace, string $originalTransactionId): array;

    /** Whether the original has a non-voided linked refund. */
    public function hasLiveRefund(WorkspaceScope $workspace, string $originalTransactionId): bool;

    /** Includes only non-voided refund movements. The original must already be locked by the caller. */
    public function refundedAmount(WorkspaceScope $workspace, string $originalTransactionId): DecimalValue;
}
