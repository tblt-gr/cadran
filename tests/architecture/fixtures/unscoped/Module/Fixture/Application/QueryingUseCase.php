<?php

declare(strict_types=1);

namespace App\Module\Fixture\Application;

use Doctrine\DBAL\Connection;

/**
 * Representative violation: a use case that reaches for a workspace-scoped
 * table itself instead of going through a repository.
 */
final readonly class QueryingUseCase
{
    public function __construct(private Connection $connection)
    {
    }

    public function count(string $workspaceId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_events WHERE workspace_id = ?',
            [$workspaceId],
        );
    }
}
