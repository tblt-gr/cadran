<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface TransactionRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?Transaction;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Transaction;

    /**
     * The full filter surface of TX-009: every filter combines as a
     * conjunction, and repeated values of a multi-valued filter combine as a
     * disjunction. Ordering is always `booked_on DESC, id DESC`.
     *
     * @return list<Transaction>
     */
    public function search(
        WorkspaceScope $workspace,
        TransactionFilters $filters,
        int $limit,
        ?TransactionPosition $after,
    ): array;

    /**
     * The latest (updated_at, id) pair written in the workspace, or null when
     * it holds no transaction at all. Used to detect a background change
     * between two pages of the same keyset query.
     */
    public function watermark(WorkspaceScope $workspace): ?TransactionWatermark;

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
