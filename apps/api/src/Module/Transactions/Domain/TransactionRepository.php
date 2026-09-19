<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\AssetAmount;
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

    /**
     * The pending rows of one account eligible to settle an incoming movement
     * of exactly this amount, booked within $windowDays of $bookedOn: still
     * pending, not already waiting for a human, not a transfer leg, not a
     * refund and not an original that still carries a live refund. Every
     * filter is applied before $limit truncates the scan, so a row inside the
     * window is never lost behind an unrelated backlog on the same account.
     * Ordered by closeness to $bookedOn, then id, for a deterministic tie
     * break. $lock takes the row locks in that same order, so two reviewers
     * resolving the same ambiguity serialise instead of producing two
     * contradictory outcomes.
     *
     * @return list<Transaction>
     */
    public function listPendingByAccount(
        WorkspaceScope $workspace,
        string $accountId,
        AssetAmount $amount,
        \DateTimeImmutable $bookedOn,
        int $windowDays,
        int $limit,
        bool $lock,
    ): array;

    /**
     * Σ amount of the booked rows of one account whose `booked_on` lies in
     * [$from, $to] (both inclusive), one entry per asset, exact to the storage
     * scale. Pending, voided and rejected rows never count; transfer legs and
     * adjustments do, since they move the account balance. An empty list means
     * the window holds no booked movement at all.
     *
     * @return list<AssetAmount>
     */
    public function sumBookedMovements(
        WorkspaceScope $workspace,
        string $accountId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;

    /**
     * The pending rows of one account booked within [$from, $to], oldest first
     * then by identifier, truncated to $limit. They contribute to no sum: they
     * are listed so a discrepancy can be attributed to them.
     *
     * @return list<Transaction>
     */
    public function listPendingInPeriod(
        WorkspaceScope $workspace,
        string $accountId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
    ): array;

    public function countPendingInPeriod(
        WorkspaceScope $workspace,
        string $accountId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): int;

    /** PENDING rows of the whole workspace booked between two days, inclusive. */
    public function countPendingInWorkspace(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): int;

    /**
     * The live row of one account already claiming this external identifier,
     * whatever its state. A settled or reviewed movement must be recognised
     * before a redelivery tries to create a second row for the same money.
     */
    public function findBySourceRef(WorkspaceScope $workspace, string $accountId, string $sourceRef, bool $lock): ?Transaction;

    /** @throws DuplicateSourceReference when the external identifier is already claimed */
    public function add(Transaction $transaction): void;

    /**
     * Returns false when the expected version is stale.
     *
     * @throws DuplicateSourceReference when the external identifier is already claimed
     */
    public function update(Transaction $transaction, int $expectedVersion): bool;
}
