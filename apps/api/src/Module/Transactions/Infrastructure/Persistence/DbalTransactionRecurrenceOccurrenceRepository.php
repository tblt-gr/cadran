<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\OccurrenceStatus;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Every read joins the recurrence: it carries the denomination an instalment is
 * expressed in, and both sides of the join state their own workspace predicate
 * so neither table can widen the scope the caller asked for.
 */
#[AsAlias(TransactionRecurrenceOccurrenceRepository::class)]
final readonly class DbalTransactionRecurrenceOccurrenceRepository implements TransactionRecurrenceOccurrenceRepository
{
    private const string TABLE = 'transaction_recurrence_occurrences';

    public function __construct(private Connection $connection)
    {
    }

    public function addAll(array $occurrences): void
    {
        foreach ($occurrences as $occurrence) {
            $this->connection->insert(self::TABLE, [
                'workspace_id' => $occurrence->workspace->id,
                ...TransactionRecurrenceRow::occurrenceColumns($occurrence),
            ]);
        }
    }

    public function listForRecurrence(WorkspaceScope $workspace, string $recurrenceId, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.TransactionRecurrenceRow::OCCURRENCE_COLUMNS.' FROM transaction_recurrence_occurrences o JOIN transaction_recurrences r ON r.workspace_id = o.workspace_id AND r.id = o.recurrence_id WHERE o.workspace_id = :workspace_id AND r.workspace_id = :workspace_id AND o.recurrence_id = :recurrence_id AND o.expected_on >= :from_date AND o.expected_on <= :to_date ORDER BY o.expected_on, o.id LIMIT :limit',
            [
                'workspace_id' => $workspace->id, 'recurrence_id' => $recurrenceId,
                'from_date' => $from->format('Y-m-d'), 'to_date' => $to->format('Y-m-d'), 'limit' => $limit,
            ],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): TransactionRecurrenceOccurrence => TransactionRecurrenceRow::hydrateOccurrence($row, $workspace), $rows);
    }

    public function scheduledDatesFrom(WorkspaceScope $workspace, string $recurrenceId, \DateTimeImmutable $from): array
    {
        return array_map(TransactionRow::text(...), $this->connection->fetchFirstColumn(
            'SELECT o.expected_on FROM transaction_recurrence_occurrences o JOIN transaction_recurrences r ON r.workspace_id = o.workspace_id AND r.id = o.recurrence_id WHERE o.workspace_id = :workspace_id AND r.workspace_id = :workspace_id AND o.recurrence_id = :recurrence_id AND o.expected_on >= :from_date ORDER BY o.expected_on',
            ['workspace_id' => $workspace->id, 'recurrence_id' => $recurrenceId, 'from_date' => $from->format('Y-m-d')],
        ));
    }

    public function deleteUnmatchedFrom(WorkspaceScope $workspace, string $recurrenceId, \DateTimeImmutable $from): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM transaction_recurrence_occurrences WHERE workspace_id = :workspace_id AND recurrence_id = :recurrence_id AND expected_on >= :from_date AND matched_transaction_id IS NULL',
            ['workspace_id' => $workspace->id, 'recurrence_id' => $recurrenceId, 'from_date' => $from->format('Y-m-d')],
        );
    }

    public function unmatchedNear(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.TransactionRecurrenceRow::OCCURRENCE_COLUMNS.' FROM transaction_recurrence_occurrences o JOIN transaction_recurrences r ON r.workspace_id = o.workspace_id AND r.id = o.recurrence_id WHERE o.workspace_id = :workspace_id AND r.workspace_id = :workspace_id AND r.account_id = :account_id AND r.archived_at IS NULL AND o.matched_transaction_id IS NULL AND o.expected_on >= :from_date AND o.expected_on <= :to_date ORDER BY o.expected_on, o.id',
            [
                'workspace_id' => $workspace->id, 'account_id' => $accountId,
                'from_date' => $from->format('Y-m-d'), 'to_date' => $to->format('Y-m-d'),
            ],
        );

        return array_map(static fn (array $row): TransactionRecurrenceOccurrence => TransactionRecurrenceRow::hydrateOccurrence($row, $workspace), $rows);
    }

    public function findByMatchedTransaction(WorkspaceScope $workspace, string $transactionId): ?TransactionRecurrenceOccurrence
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.TransactionRecurrenceRow::OCCURRENCE_COLUMNS.' FROM transaction_recurrence_occurrences o JOIN transaction_recurrences r ON r.workspace_id = o.workspace_id AND r.id = o.recurrence_id WHERE o.workspace_id = :workspace_id AND r.workspace_id = :workspace_id AND o.matched_transaction_id = :transaction_id',
            ['workspace_id' => $workspace->id, 'transaction_id' => $transactionId],
        );

        return false === $row ? null : TransactionRecurrenceRow::hydrateOccurrence($row, $workspace);
    }

    public function claim(WorkspaceScope $workspace, string $occurrenceId, string $transactionId, \DateTimeImmutable $matchedAt): bool
    {
        // The `matched_transaction_id IS NULL` predicate is the arbitration: two
        // concurrent movements reaching the same instalment leave exactly one
        // winner, without either of them having to take a lock first.
        return 1 === $this->connection->executeStatement(
            "UPDATE transaction_recurrence_occurrences SET matched_transaction_id = :transaction_id, matched_at = :matched_at, status = 'RECEIVED' WHERE workspace_id = :workspace_id AND id = :id AND matched_transaction_id IS NULL",
            [
                'workspace_id' => $workspace->id, 'id' => $occurrenceId, 'transaction_id' => $transactionId,
                'matched_at' => $matchedAt->format('Y-m-d H:i:s.uP'),
            ],
        );
    }

    public function release(WorkspaceScope $workspace, string $occurrenceId): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE transaction_recurrence_occurrences SET matched_transaction_id = NULL, matched_at = NULL, status = :status WHERE workspace_id = :workspace_id AND id = :id AND matched_transaction_id IS NOT NULL',
            ['workspace_id' => $workspace->id, 'id' => $occurrenceId, 'status' => OccurrenceStatus::EXPECTED->value],
        );
    }

    public function earliestExpectedOn(WorkspaceScope $workspace, string $recurrenceId): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            'SELECT min(o.expected_on) FROM transaction_recurrence_occurrences o JOIN transaction_recurrences r ON r.workspace_id = o.workspace_id AND r.id = o.recurrence_id WHERE o.workspace_id = :workspace_id AND r.workspace_id = :workspace_id AND o.recurrence_id = :recurrence_id AND o.matched_transaction_id IS NULL',
            ['workspace_id' => $workspace->id, 'recurrence_id' => $recurrenceId],
        );

        return self::day($value);
    }

    public function lastExpectedOn(WorkspaceScope $workspace, string $recurrenceId): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            'SELECT max(o.expected_on) FROM transaction_recurrence_occurrences o JOIN transaction_recurrences r ON r.workspace_id = o.workspace_id AND r.id = o.recurrence_id WHERE o.workspace_id = :workspace_id AND r.workspace_id = :workspace_id AND o.recurrence_id = :recurrence_id',
            ['workspace_id' => $workspace->id, 'recurrence_id' => $recurrenceId],
        );

        return self::day($value);
    }

    private static function day(mixed $value): ?\DateTimeImmutable
    {
        return false === $value || null === $value
            ? null
            : new \DateTimeImmutable(TransactionRow::text($value), new \DateTimeZone('UTC'));
    }
}
