<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * What a calling module states about an operation it just performed. The event
 * identifier and the timestamp are the audit module's own responsibility, so
 * they are deliberately absent here.
 */
final readonly class AuditEventRecord
{
    public function __construct(
        public WorkspaceScope $workspace,
        public ?string $actorId,
        public string $eventType,
        public string $entityType,
        public string $entityId,
        public AuditDiff $diff,
    ) {
    }
}
