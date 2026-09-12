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

    /** Whether the original has a live (non-terminal) linked refund. */
    public function hasLiveRefund(WorkspaceScope $workspace, string $originalTransactionId): bool;

    /** Includes only live refund movements. The original must already be locked by the caller. */
    public function refundedAmount(WorkspaceScope $workspace, string $originalTransactionId): DecimalValue;

    /**
     * Batched for a transaction page: the original's identity for every row
     * that is itself a refund, without hydrating the original's own splits.
     *
     * @param list<string> $refundTransactionIds
     *
     * @return array<string, array{originalId: string, originalLabel: string}> keyed by refund transaction id
     */
    public function originalsByRefundTransactionId(WorkspaceScope $workspace, array $refundTransactionIds): array;

    /**
     * Batched for a transaction page: the live refunded total for every
     * original that carries at least one live refund.
     *
     * @param list<string> $originalTransactionIds
     *
     * @return array<string, DecimalValue> keyed by original transaction id
     */
    public function liveRefundedAmounts(WorkspaceScope $workspace, array $originalTransactionIds): array;
}
