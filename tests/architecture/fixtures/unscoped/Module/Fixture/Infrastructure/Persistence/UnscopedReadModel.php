<?php

declare(strict_types=1);

namespace App\Module\Fixture\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Representative violation: a repository that reads a workspace-scoped table by
 * identifier alone. Exercised by scripts/test-architecture.sh to prove the
 * workspace-scope guard still rejects it.
 */
final readonly class UnscopedReadModel
{
    private const string TABLE = 'audit_events';

    public function __construct(private Connection $connection, private object $gateway)
    {
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findEvent(string $eventId): array|false
    {
        return $this->connection->fetchAssociative('SELECT id FROM audit_events WHERE id = ?', [$eventId]);
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findEventWhileProjectingItsWorkspace(string $eventId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT id, workspace_id FROM audit_events WHERE id = ?',
            [$eventId],
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findEventThroughTableConstant(string $eventId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT id FROM '.self::TABLE.' WHERE id = ?',
            [$eventId],
        );
    }

    /**
     * Representative violation: a cross-workspace write. The workspace_id here
     * is the column being changed, not a restriction on the rows reached.
     */
    public function moveEventToAnotherWorkspace(string $eventId, string $workspaceId): void
    {
        $this->connection->update('audit_events', ['workspace_id' => $workspaceId], ['id' => $eventId]);
    }

    /**
     * Representative violation: a correctly scoped query sitting next to an
     * unscoped one. Analysis per method would let the first vouch for the
     * second.
     */
    public function findEventBesideAScopedQuery(string $workspaceId, string $eventId): mixed
    {
        $this->connection->fetchAllAssociative(
            'SELECT id FROM audit_events WHERE workspace_id = :workspace',
            ['workspace' => $workspaceId],
        );

        return $this->connection->fetchAssociative('SELECT id FROM audit_events WHERE id = :id', ['id' => $eventId]);
    }

    /**
     * Representative violation: one scoped join participant must not vouch for
     * another workspace-scoped table in the same SQL statement.
     */
    public function findEventThroughPartiallyScopedJoin(string $workspaceId, string $eventId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT a.id FROM audit_events a
             JOIN identity_workspace_memberships m ON m.workspace_id = :workspace
             WHERE a.id = :id',
            ['workspace' => $workspaceId, 'id' => $eventId],
        );
    }

    /**
     * Representative alternate: reusing an alias in separate UNION branches
     * must still require one predicate for each table occurrence.
     *
     * @return list<array<string, mixed>>
     */
    public function findEventsThroughPartiallyScopedUnion(string $workspaceId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id FROM audit_events a WHERE a.workspace_id = :workspace
             UNION ALL
             SELECT a.id FROM audit_events a WHERE a.created_at IS NOT NULL',
            ['workspace' => $workspaceId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findEventsThroughPartiallyScopedCommaJoin(string $workspaceId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id FROM audit_events a, identity_workspace_memberships m
             WHERE a.workspace_id = :workspace',
            ['workspace' => $workspaceId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findEventsThroughSuffixAlias(string $workspaceId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id FROM audit_events a
             JOIN identity_workspace_memberships data ON data.workspace_id = :workspace
             WHERE a.id IS NOT NULL',
            ['workspace' => $workspaceId],
        );
    }

    /**
     * Representative violation: a recognised safe call must not mask a second
     * occurrence executed through an unrecognised query API.
     */
    public function findEventThroughUnrecognisedGateway(string $workspaceId, string $eventId): mixed
    {
        $this->connection->fetchAssociative(
            'SELECT id FROM audit_events WHERE workspace_id = :workspace',
            ['workspace' => $workspaceId],
        );

        return $this->gateway->run('SELECT id FROM audit_events WHERE id = :id', ['id' => $eventId]);
    }

    public function findEventThroughUnrecognisedGatewayArgument(string $workspaceId, string $eventId): mixed
    {
        $this->connection->fetchAssociative(
            'SELECT id FROM audit_events WHERE workspace_id = :workspace',
            ['workspace' => $workspaceId],
        );

        return $this->gateway->run(
            ['id' => $eventId],
            'SELECT id FROM audit_events WHERE id = :id',
        );
    }
}
