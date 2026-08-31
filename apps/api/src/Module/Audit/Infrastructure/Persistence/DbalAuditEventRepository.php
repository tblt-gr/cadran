<?php

declare(strict_types=1);

namespace App\Module\Audit\Infrastructure\Persistence;

use App\Module\Audit\Domain\AuditEvent;
use App\Module\Audit\Domain\AuditEventRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AuditEventRepository::class)]
final readonly class DbalAuditEventRepository implements AuditEventRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(AuditEvent $event): void
    {
        // The insert joins whatever transaction the caller opened, so the event
        // is committed with the operation it describes, or not at all.
        $this->connection->insert('audit_events', [
            'id' => $event->id,
            'workspace_id' => $event->workspaceId,
            'actor_id' => $event->actorId,
            'event_type' => $event->eventType,
            'entity_type' => $event->entityType,
            'entity_id' => $event->entityId,
            'before_json' => self::encode($event->diff->before),
            'after_json' => self::encode($event->diff->after),
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /**
     * An absent side is stored as SQL NULL rather than an empty object, so a
     * creation and a change to nothing stay distinguishable.
     *
     * @param array<string, string|null> $attributes
     */
    private static function encode(array $attributes): ?string
    {
        if ([] === $attributes) {
            return null;
        }

        return json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
