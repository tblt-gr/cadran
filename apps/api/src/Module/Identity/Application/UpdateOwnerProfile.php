<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Identity\Domain\DisplayName;

/**
 * Edits the authenticated owner's display name. The email is deliberately not
 * editable here: it is the firewall identifier, so changing it would rewrite
 * every open session's subject and belongs to its own decision.
 */
final readonly class UpdateOwnerProfile
{
    public function __construct(
        private TransactionManager $transactionManager,
        private CallerWorkspaceContext $callerContext,
        private AuthenticationUserRepository $users,
        private OwnerProfileWriter $profiles,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(UpdateOwnerProfileInput $input): OwnerProfileView
    {
        $context = $this->callerContext->resolveContext();

        $user = $this->users->findById($context->actorId);
        if (null === $user) {
            throw new OwnerAccountNotProvisioned();
        }

        $displayName = DisplayName::fromString($input->displayName);
        if ($displayName->value === $user->displayName) {
            // Nothing changed: no write, and no audit entry claiming one.
            return new OwnerProfileView($user->id, $user->email, $user->displayName);
        }

        return $this->transactionManager->transactional(function () use ($context, $user, $displayName): OwnerProfileView {
            if (!$this->profiles->updateDisplayName($user->id, $displayName->value)) {
                throw new OwnerAccountNotProvisioned();
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: IdentityAuditEvents::PROFILE_UPDATED,
                entityType: IdentityAuditEvents::ENTITY_USER,
                entityId: $user->id,
                diff: AuditDiff::change(
                    ['displayName' => $user->displayName],
                    ['displayName' => $displayName->value],
                ),
            ));

            return new OwnerProfileView($user->id, $user->email, $displayName->value);
        });
    }
}
