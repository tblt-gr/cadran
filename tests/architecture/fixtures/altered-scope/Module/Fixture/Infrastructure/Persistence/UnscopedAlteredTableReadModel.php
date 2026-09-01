<?php

declare(strict_types=1);

namespace App\Module\Fixture\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final readonly class UnscopedAlteredTableReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findRecord(string $recordId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT id FROM later_scoped_records WHERE id = ?',
            [$recordId],
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findRecordAfterColumnRename(string $recordId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT id FROM renamed_column_records WHERE id = ?',
            [$recordId],
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findRecordAfterTableRename(string $recordId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT id FROM renamed_table_records WHERE id = ?',
            [$recordId],
        );
    }

    /**
     * Representative violation on a table whose CREATE TABLE spans several
     * lines and closes a parenthesis before workspace_id.
     */
    public function findMultilineRecord(string $id): mixed
    {
        return $this->connection->fetchAssociative('SELECT id FROM multiline_records WHERE id = :id', ['id' => $id]);
    }
}
