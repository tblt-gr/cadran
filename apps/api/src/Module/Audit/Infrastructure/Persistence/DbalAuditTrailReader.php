<?php

declare(strict_types=1);

namespace App\Module\Audit\Infrastructure\Persistence;

use App\Module\Audit\Application\AuditTrailCursor;
use App\Module\Audit\Application\AuditTrailEntry;
use App\Module\Audit\Application\AuditTrailReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AuditTrailReader::class)]
final readonly class DbalAuditTrailReader implements AuditTrailReader
{
    private const string COLUMNS = 'id, actor_id, event_type, entity_type, entity_id, before_json, after_json, occurred_at';

    public function __construct(private Connection $connection)
    {
    }

    public function readPage(WorkspaceScope $workspace, int $limit, ?AuditTrailCursor $after): array
    {
        // The workspace predicate is not optional and not caller-supplied: a
        // cursor from another workspace still reads nothing here.
        $sql = 'SELECT '.self::COLUMNS.' FROM audit_events WHERE workspace_id = :workspace';
        $parameters = ['workspace' => $workspace->id, 'limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        if (null !== $after) {
            // Row-value comparison matches the (workspace_id, occurred_at DESC,
            // id DESC) index, so paging stays a single index scan.
            $sql .= ' AND (occurred_at, id) < (:cursorAt, :cursorId)';
            $parameters['cursorAt'] = $after->occurredAt->format('Y-m-d H:i:s.uP');
            $parameters['cursorId'] = $after->eventId;
        }

        $sql .= ' ORDER BY occurred_at DESC, id DESC LIMIT :limit';

        $entries = [];
        foreach ($this->connection->fetchAllAssociative($sql, $parameters, $types) as $row) {
            $entries[] = self::hydrate($row);
        }

        return $entries;
    }

    public function findEvent(WorkspaceScope $workspace, string $eventId): ?AuditTrailEntry
    {
        // The identifier alone never selects a row: the workspace is part of
        // the predicate, so a foreign event is simply absent.
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM audit_events WHERE workspace_id = :workspace AND id = :id',
            ['workspace' => $workspace->id, 'id' => $eventId],
        );

        return false === $row ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AuditTrailEntry
    {
        return new AuditTrailEntry(
            id: self::asString($row['id'] ?? null),
            actorId: null === ($row['actor_id'] ?? null) ? null : self::asString($row['actor_id']),
            eventType: self::asString($row['event_type'] ?? null),
            entityType: self::asString($row['entity_type'] ?? null),
            entityId: self::asString($row['entity_id'] ?? null),
            before: self::decode($row['before_json'] ?? null),
            after: self::decode($row['after_json'] ?? null),
            occurredAt: new \DateTimeImmutable(self::asString($row['occurred_at'] ?? null)),
        );
    }

    private static function asString(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    /**
     * @return array<string, string|null>|null
     */
    private static function decode(mixed $json): ?array
    {
        if (null === $json) {
            return null;
        }

        $decoded = json_decode(self::asString($json), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('An audit diff must be stored as a JSON object.');
        }

        $attributes = [];
        foreach ($decoded as $name => $value) {
            $attributes[(string) $name] = null === $value ? null : self::asString($value);
        }

        return $attributes;
    }
}
