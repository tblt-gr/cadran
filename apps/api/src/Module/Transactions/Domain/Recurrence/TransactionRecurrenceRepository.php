<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The confirmed recurrences of one workspace. Every method takes the scope it
 * may read or write, so a forecast can never be built from, or applied to,
 * another workspace's schedule.
 */
interface TransactionRecurrenceRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?TransactionRecurrence;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?TransactionRecurrence;

    /** @return list<TransactionRecurrence> in schedule order: next expected date, then identifier */
    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array;

    public function count(WorkspaceScope $workspace, bool $includeArchived): int;

    /** @return list<TransactionRecurrence> the live recurrences, in schedule order and bounded by the caller */
    public function activeInOrder(WorkspaceScope $workspace, int $limit): array;

    /** @return list<TransactionRecurrence> the live recurrences of one account, in schedule order */
    public function activeForAccount(WorkspaceScope $workspace, string $accountId): array;

    public function add(TransactionRecurrence $recurrence): void;

    /** Returns false when the expected version is stale. */
    public function update(TransactionRecurrence $recurrence, int $expectedVersion): bool;

    /**
     * Moves the schedule pointer only. Settling an instalment is not an edit of
     * the recurrence, so it leaves the version untouched and never races a
     * person editing the schedule at the same moment.
     */
    public function advanceNextExpectedOn(WorkspaceScope $workspace, string $id, \DateTimeImmutable $nextExpectedOn): void;
}
