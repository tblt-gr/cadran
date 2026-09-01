<?php

declare(strict_types=1);

namespace App\Module\Audit\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * One recorded change to a security-sensitive or financially meaningful record.
 *
 * An event always belongs to a workspace: the trail is read back through that
 * scope and nothing else. The actor is nullable because two legitimate writers
 * carry no authenticated session — the provisioning console command, and the
 * one-time first-run password flow that runs before any session exists.
 */
final readonly class AuditEvent
{
    private const string EVENT_TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,30}\.[a-z][a-z0-9_]{0,30}$/';
    private const string ENTITY_TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,30}$/';

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public ?string $actorId,
        public string $eventType,
        public string $entityType,
        public string $entityId,
        public AuditDiff $diff,
        public \DateTimeImmutable $occurredAt,
    ) {
        if ('' === $id || '' === $entityId) {
            throw new \InvalidArgumentException('An audit event requires an identifier and an entity.');
        }

        if ('' === $actorId) {
            throw new \InvalidArgumentException('An audit event actor is either absent or identified.');
        }

        if (1 !== preg_match(self::EVENT_TYPE_PATTERN, $eventType)) {
            throw new \InvalidArgumentException(sprintf('Unsupported audit event type "%s".', $eventType));
        }

        if (1 !== preg_match(self::ENTITY_TYPE_PATTERN, $entityType)) {
            throw new \InvalidArgumentException(sprintf('Unsupported audit entity type "%s".', $entityType));
        }
    }
}
