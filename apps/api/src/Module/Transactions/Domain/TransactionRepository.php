<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface TransactionRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?Transaction;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Transaction;

    /**
     * $uncategorized restricts the page to the non-voided transactions carrying
     * no split at all — the "to categorise" queue — and takes precedence over
     * $includeVoided, since a voided movement never belongs in that queue.
     *
     * @return list<Transaction>
     */
    public function list(
        WorkspaceScope $workspace,
        ?string $accountId,
        bool $includeVoided,
        int $limit,
        ?TransactionPosition $after,
        bool $uncategorized = false,
    ): array;

    public function add(Transaction $transaction): void;

    /** Returns false when the expected version is stale. */
    public function update(Transaction $transaction, int $expectedVersion): bool;
}
