<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Double;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\BookedMovementFact;
use App\Module\Transactions\Application\BookedMovementReader;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionPosition;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Domain\TransactionWatermark;

/**
 * A minimal transaction store for the Budget use cases that only ever
 * `search()` a bounded date range. Every method this ticket does not touch
 * throws, so a use case that starts relying on one is caught here first.
 */
final class InMemoryTransactionRepository implements TransactionRepository, BookedMovementReader
{
    /** @var list<Transaction> */
    private array $transactions;

    public function __construct(Transaction ...$transactions)
    {
        $this->transactions = array_values($transactions);
    }

    public function find(WorkspaceScope $workspace, string $id): ?Transaction
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function findMany(WorkspaceScope $workspace, array $ids): array
    {
        return array_values(array_filter(
            $this->transactions,
            static fn (Transaction $transaction): bool => $transaction->workspace->equals($workspace)
                && in_array($transaction->id, $ids, true),
        ));
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Transaction
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function findManyForUpdate(WorkspaceScope $workspace, array $ids): array
    {
        return $this->findMany($workspace, $ids);
    }

    public function countMatching(WorkspaceScope $workspace, TransactionFilters $filters): int
    {
        return count($this->search($workspace, $filters, PHP_INT_MAX, null));
    }

    public function search(WorkspaceScope $workspace, TransactionFilters $filters, int $limit, ?TransactionPosition $after): array
    {
        $rows = array_values(array_filter($this->transactions, static function (Transaction $transaction) use ($workspace, $filters): bool {
            if (!$transaction->workspace->equals($workspace)) {
                return false;
            }
            if ([] !== $filters->states && !in_array($transaction->state, $filters->states, true)) {
                return false;
            }
            if (null !== $filters->from && $transaction->bookedOn < $filters->from) {
                return false;
            }
            if (null !== $filters->to && $transaction->bookedOn > $filters->to) {
                return false;
            }

            return true;
        }));

        return array_slice($rows, 0, $limit);
    }

    public function between(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        $booked = array_values(array_filter($this->transactions, static fn (Transaction $transaction): bool => $transaction->workspace->equals($workspace)
            && TransactionState::BOOKED === $transaction->state
            && $transaction->bookedOn >= $from
            && $transaction->bookedOn <= $to));
        usort($booked, static fn (Transaction $left, Transaction $right): int => [$left->bookedOn, $left->id] <=> [$right->bookedOn, $right->id]);

        return array_map(
            static fn (Transaction $transaction): BookedMovementFact => new BookedMovementFact($transaction->id, $transaction->accountId, $transaction->nature->value, $transaction->amount),
            array_slice($booked, 0, $limit),
        );
    }

    public function watermark(WorkspaceScope $workspace): ?TransactionWatermark
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function listForCategorization(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit, bool $lock): array
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function listPendingByAccount(WorkspaceScope $workspace, string $accountId, AssetAmount $amount, \DateTimeImmutable $bookedOn, int $windowDays, int $limit, bool $lock): array
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function sumBookedMovements(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function listPendingInPeriod(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function countPendingInPeriod(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function countPendingInWorkspace(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function findBySourceRef(WorkspaceScope $workspace, string $accountId, string $sourceRef, bool $lock): ?Transaction
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function add(Transaction $transaction): void
    {
        throw new \LogicException('Not needed by the Budget module.');
    }

    public function update(Transaction $transaction, int $expectedVersion): bool
    {
        throw new \LogicException('Not needed by the Budget module.');
    }
}
