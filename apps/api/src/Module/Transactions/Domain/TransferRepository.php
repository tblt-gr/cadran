<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface TransferRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?Transfer;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Transfer;

    /**
     * A transaction belongs to at most one transfer, as either leg or its fee.
     * Used to refuse editing or voiding a leg through the transaction
     * endpoints instead of the transfer's own.
     */
    public function findByLegTransactionId(WorkspaceScope $workspace, string $transactionId): ?Transfer;

    /**
     * Bulk lookup behind the transaction list's transfer marker: which of
     * these transactions is a transfer leg or fee, and which transfer.
     *
     * @param list<string> $transactionIds
     *
     * @return array<string, string> transaction id => transfer id
     */
    public function markersForLegs(WorkspaceScope $workspace, array $transactionIds): array;

    public function add(Transfer $transfer): void;

    /** Returns false when the expected version is stale. */
    public function update(Transfer $transfer, int $expectedVersion): bool;
}
