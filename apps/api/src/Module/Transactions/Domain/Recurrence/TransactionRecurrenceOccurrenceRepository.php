<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The generated forecast of one workspace.
 *
 * Claiming and releasing are expressed as conditional writes rather than as a
 * read followed by a save: an instalment must be settled by exactly one
 * movement even when two requests reach it at the same moment, and the database
 * is the only place that can arbitrate that.
 */
interface TransactionRecurrenceOccurrenceRepository
{
    /** @param list<TransactionRecurrenceOccurrence> $occurrences */
    public function addAll(array $occurrences): void;

    /** @return list<TransactionRecurrenceOccurrence> inside the window, in expected-date order */
    public function listForRecurrence(WorkspaceScope $workspace, string $recurrenceId, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array;

    /** @return list<string> the ISO days already generated on or after `$from`, in order */
    public function scheduledDatesFrom(WorkspaceScope $workspace, string $recurrenceId, \DateTimeImmutable $from): array;

    /** Drops the unmatched instalments expected on or after `$from`; matched and past ones are immutable. */
    public function deleteUnmatchedFrom(WorkspaceScope $workspace, string $recurrenceId, \DateTimeImmutable $from): int;

    /**
     * The unmatched instalments of the live recurrences of one account inside a
     * date window — the only candidates a real movement may settle.
     *
     * @return list<TransactionRecurrenceOccurrence>
     */
    public function unmatchedNear(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function findByMatchedTransaction(WorkspaceScope $workspace, string $transactionId): ?TransactionRecurrenceOccurrence;

    /** Settles the instalment; false when it was already settled by another movement. */
    public function claim(WorkspaceScope $workspace, string $occurrenceId, string $transactionId, \DateTimeImmutable $matchedAt): bool;

    /** Returns a settled instalment to the expected set; false when it was not settled. */
    public function release(WorkspaceScope $workspace, string $occurrenceId): bool;

    /** The earliest instalment still awaiting a movement, if any. */
    public function earliestExpectedOn(WorkspaceScope $workspace, string $recurrenceId): ?\DateTimeImmutable;

    /** The last instalment generated so far, whatever its status. */
    public function lastExpectedOn(WorkspaceScope $workspace, string $recurrenceId): ?\DateTimeImmutable;
}
