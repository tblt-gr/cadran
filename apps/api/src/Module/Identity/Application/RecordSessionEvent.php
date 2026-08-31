<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;

/**
 * Turns an authentication outcome into an audit event.
 *
 * Two deliberate silences. An attempt on an email that matches no account
 * records nothing: there is no workspace to attribute it to, and storing the
 * submitted string would put attacker-controlled text in the trail. A session
 * event carries no diff either — the act itself is the whole content, and a
 * credential must never be described, not even by shape.
 */
final readonly class RecordSessionEvent
{
    public function __construct(
        private AuthenticationUserRepository $users,
        private WorkspaceMembershipReader $memberships,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(SessionAuditIntent $intent): void
    {
        $user = $this->users->findByEmail($intent->email);
        if (null === $user) {
            return;
        }

        $membership = $this->memberships->findForUser($user->id);
        if (null === $membership) {
            return;
        }

        ($this->recordAuditEvent)(new AuditEventRecord(
            workspaceId: $membership->workspaceId,
            actorId: $user->id,
            eventType: $intent->eventType,
            entityType: IdentityAuditEvents::ENTITY_USER,
            entityId: $user->id,
            diff: AuditDiff::none(),
        ));
    }
}
