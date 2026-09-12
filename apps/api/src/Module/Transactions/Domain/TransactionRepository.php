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

    /**
     * The movements a categorisation run may touch within a booked period, in
     * identifier order: live income, expense, fee and adjustment rows that are
     * neither a transfer leg nor an original carrying live refunds. $lock takes
     * the row locks, in that same order, for a run that is about to write.
     *
     * @return list<Transaction>
     */
    public function listForCategorization(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        bool $lock,
    ): array;

    public function add(Transaction $transaction): void;

    /** Returns false when the expected version is stale. */
    public function update(Transaction $transaction, int $expectedVersion): bool;
}
