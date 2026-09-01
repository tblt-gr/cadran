<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

use App\Module\Audit\Domain\AuditEvent;
use App\Module\Audit\Domain\AuditEventRepository;
use App\Module\Foundation\Domain\UuidGenerator;

/**
 * The single write path into the trail. Callers invoke it from inside the
 * transaction that carries their business invariant, so an operation and its
 * audit event either both land or neither does.
 */
final readonly class RecordAuditEvent
{
    public function __construct(
        private AuditEventRepository $events,
        private UuidGenerator $uuidGenerator,
    ) {
    }

    public function __invoke(AuditEventRecord $record): void
    {
        $this->events->append(new AuditEvent(
            id: $this->uuidGenerator->generate(),
            workspace: $record->workspace,
            actorId: $record->actorId,
            eventType: $record->eventType,
            entityType: $record->entityType,
            entityId: $record->entityId,
            diff: $record->diff,
            occurredAt: new \DateTimeImmutable(),
        ));
    }
}
