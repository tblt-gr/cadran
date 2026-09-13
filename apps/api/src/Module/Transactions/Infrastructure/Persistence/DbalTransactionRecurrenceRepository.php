<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TransactionRecurrenceRepository::class)]
final readonly class DbalTransactionRecurrenceRepository implements TransactionRecurrenceRepository
{
    private const string TABLE = 'transaction_recurrences';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?TransactionRecurrence
    {
        return $this->one($workspace, $id, false);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?TransactionRecurrence
    {
        return $this->one($workspace, $id, true);
    }

    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.TransactionRecurrenceRow::COLUMNS.' FROM transaction_recurrences WHERE workspace_id = :workspace_id'
            .($includeArchived ? '' : ' AND archived_at IS NULL').' ORDER BY next_expected_on, id LIMIT :limit OFFSET :offset',
            ['workspace_id' => $workspace->id, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): TransactionRecurrence => TransactionRecurrenceRow::hydrate($row, $workspace), $rows);
    }

    public function count(WorkspaceScope $workspace, bool $includeArchived): int
    {
        return (int) TransactionRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM transaction_recurrences WHERE workspace_id = :workspace_id'
            .($includeArchived ? '' : ' AND archived_at IS NULL'),
            ['workspace_id' => $workspace->id],
        ));
    }

    public function activeInOrder(WorkspaceScope $workspace, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.TransactionRecurrenceRow::COLUMNS.' FROM transaction_recurrences WHERE workspace_id = :workspace_id AND archived_at IS NULL ORDER BY next_expected_on, id LIMIT :limit',
            ['workspace_id' => $workspace->id, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): TransactionRecurrence => TransactionRecurrenceRow::hydrate($row, $workspace), $rows);
    }

    public function activeForAccount(WorkspaceScope $workspace, string $accountId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.TransactionRecurrenceRow::COLUMNS.' FROM transaction_recurrences WHERE workspace_id = :workspace_id AND account_id = :account_id AND archived_at IS NULL ORDER BY next_expected_on, id',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId],
        );

        return array_map(static fn (array $row): TransactionRecurrence => TransactionRecurrenceRow::hydrate($row, $workspace), $rows);
    }

    public function add(TransactionRecurrence $recurrence): void
    {
        $this->connection->insert(self::TABLE, [
            'id' => $recurrence->id,
            'workspace_id' => $recurrence->workspace->id,
            ...TransactionRecurrenceRow::columns($recurrence),
        ]);
    }

    public function update(TransactionRecurrence $recurrence, int $expectedVersion): bool
    {
        return 1 === $this->connection->update(
            self::TABLE,
            TransactionRecurrenceRow::columns($recurrence),
            ['workspace_id' => $recurrence->workspace->id, 'id' => $recurrence->id, 'version' => $expectedVersion],
        );
    }

    public function advanceNextExpectedOn(WorkspaceScope $workspace, string $id, \DateTimeImmutable $nextExpectedOn): void
    {
        $this->connection->executeStatement(
            'UPDATE transaction_recurrences SET next_expected_on = :next_expected_on WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id, 'next_expected_on' => $nextExpectedOn->format('Y-m-d')],
        );
    }

    private function one(WorkspaceScope $workspace, string $id, bool $lock): ?TransactionRecurrence
    {
        $sql = 'SELECT '.TransactionRecurrenceRow::COLUMNS.' FROM transaction_recurrences WHERE workspace_id = :workspace_id AND id = :id';
        $row = $this->connection->fetchAssociative(
            $lock ? $sql.' FOR UPDATE' : $sql,
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : TransactionRecurrenceRow::hydrate($row, $workspace);
    }
}
