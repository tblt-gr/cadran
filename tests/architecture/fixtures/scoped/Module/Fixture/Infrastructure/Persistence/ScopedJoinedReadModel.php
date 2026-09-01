<?php

declare(strict_types=1);

namespace App\Module\Fixture\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Legitimate control: every workspace-scoped join participant is constrained.
 */
final readonly class ScopedJoinedReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findEventsForMember(string $workspaceId, string $userId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id FROM audit_events AS a
             JOIN identity_workspace_memberships AS m ON m.workspace_id = :workspace
             WHERE a.workspace_id = :workspace AND m.user_id = :user',
            ['workspace' => $workspaceId, 'user' => $userId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findEventsAcrossScopedBranches(string $workspaceId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id FROM audit_events a WHERE a.workspace_id = :workspace
             UNION ALL
             SELECT a.id FROM audit_events a WHERE a.workspace_id = :workspace',
            ['workspace' => $workspaceId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findEventsThroughScopedCommaJoin(string $workspaceId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id FROM audit_events a, identity_workspace_memberships m
             WHERE a.workspace_id = :workspace AND m.workspace_id = :workspace',
            ['workspace' => $workspaceId],
        );
    }
}
