<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;

/**
 * Delegates to the real repository but runs a callback just before the rows of
 * a bulk write are locked, so a test can change the data between the moment a
 * scope was resolved and the moment it is locked.
 */
final class BeforeLockTransactionRepository implements TransactionRepository
{
    /** @var \Closure(): void|null */
    public ?\Closure $beforeLock = null;

    public function __construct(private readonly TransactionRepository $inner)
    {
    }

    public function find(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $id): ?Transaction
    {
        return $this->inner->find($workspace, $id);
    }

    public function findMany(\App\Module\Foundation\Domain\WorkspaceScope $workspace, array $ids): array
    {
        return $this->inner->findMany($workspace, $ids);
    }

    public function findForUpdate(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $id): ?Transaction
    {
        return $this->inner->findForUpdate($workspace, $id);
    }

    public function findManyForUpdate(\App\Module\Foundation\Domain\WorkspaceScope $workspace, array $ids): array
    {
        if (null !== $this->beforeLock) {
            ($this->beforeLock)();
            $this->beforeLock = null;
        }

        return $this->inner->findManyForUpdate($workspace, $ids);
    }

    public function countMatching(\App\Module\Foundation\Domain\WorkspaceScope $workspace, \App\Module\Transactions\Domain\TransactionFilters $filters): int
    {
        return $this->inner->countMatching($workspace, $filters);
    }

    public function search(
        \App\Module\Foundation\Domain\WorkspaceScope $workspace,
        \App\Module\Transactions\Domain\TransactionFilters $filters,
        int $limit,
        ?\App\Module\Transactions\Domain\TransactionPosition $after,
    ): array {
        return $this->inner->search($workspace, $filters, $limit, $after);
    }

    public function watermark(\App\Module\Foundation\Domain\WorkspaceScope $workspace): ?\App\Module\Transactions\Domain\TransactionWatermark
    {
        return $this->inner->watermark($workspace);
    }

    public function sumBookedMovements(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->inner->sumBookedMovements($workspace, $accountId, $from, $to);
    }

    public function listPendingInPeriod(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        return $this->inner->listPendingInPeriod($workspace, $accountId, $from, $to, $limit);
    }

    public function countPendingInPeriod(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $this->inner->countPendingInPeriod($workspace, $accountId, $from, $to);
    }

    public function countPendingInWorkspace(\App\Module\Foundation\Domain\WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $this->inner->countPendingInWorkspace($workspace, $from, $to);
    }

    public function findBySourceRef(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $accountId, string $sourceRef, bool $lock): ?Transaction
    {
        return $this->inner->findBySourceRef($workspace, $accountId, $sourceRef, $lock);
    }

    public function listPendingByAccount(
        \App\Module\Foundation\Domain\WorkspaceScope $workspace,
        string $accountId,
        \App\Module\Foundation\Domain\AssetAmount $amount,
        \DateTimeImmutable $bookedOn,
        int $windowDays,
        int $limit,
        bool $lock,
    ): array {
        return $this->inner->listPendingByAccount($workspace, $accountId, $amount, $bookedOn, $windowDays, $limit, $lock);
    }

    public function add(Transaction $transaction): void
    {
        $this->inner->add($transaction);
    }

    public function listForCategorization(
        \App\Module\Foundation\Domain\WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        bool $lock,
    ): array {
        return $this->inner->listForCategorization($workspace, $from, $to, $limit, $lock);
    }

    public function update(Transaction $transaction, int $expectedVersion): bool
    {
        return $this->inner->update($transaction, $expectedVersion);
    }
}
