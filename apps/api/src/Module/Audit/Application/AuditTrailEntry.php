<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

final readonly class AuditTrailEntry
{
    /**
     * @param array<string, string|null>|null $before
     * @param array<string, string|null>|null $after
     */
    public function __construct(
        public string $id,
        public ?string $actorId,
        public string $eventType,
        public string $entityType,
        public string $entityId,
        public ?array $before,
        public ?array $after,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
