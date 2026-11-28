<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\Uuid;

/**
 * Reads one event of the caller's own trail. The identifier arrives from the
 * URL, so it is untrusted: the workspace comes from the session instead, and a
 * foreign identifier is answered exactly like an unknown one.
 */
final readonly class ReadAuditEvent
{
    public function __construct(
        private CallerWorkspace $callerWorkspace,
        private AuditTrailReader $reader,
    ) {
    }

    public function __invoke(string $eventId): AuditTrailEntry
    {
        // Resolved before the identifier is even looked at: an unauthorized
        // caller learns nothing about the shape of an identifier either.
        $workspace = $this->callerWorkspace->resolve();

        if (!Uuid::isValid($eventId)) {
            throw new AuditEventNotFound('An audit event identifier is a UUID.');
        }

        $entry = $this->reader->findEvent($workspace, $eventId);
        if (null === $entry) {
            throw new AuditEventNotFound('No such audit event in this workspace.');
        }

        return $entry;
    }
}
