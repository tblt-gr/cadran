<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Identity\Domain\PasswordHasher;

/**
 * Host-side recovery: replaces the owner's password without the old one and
 * closes every session. Whoever can run the console already controls the data,
 * so no current-password proof is asked; the same password policy applies.
 */
final readonly class ResetOwnerPassword
{
    public function __construct(
        private TransactionManager $transactionManager,
        private AuthenticationUserRepository $users,
        private WorkspaceMembershipReader $memberships,
        private OwnerPasswordWriter $passwords,
        private OwnerSessionRegistry $sessions,
        private PasswordHasher $hasher,
        private RecordAuditEvent $recordAuditEvent,
        private OwnerPasswordPolicy $policy,
    ) {
    }

    public function __invoke(ResetOwnerPasswordInput $input): void
    {
        $owner = $this->users->findProvisionedOwner();
        if (null === $owner) {
            throw new OwnerAccountNotProvisioned();
        }

        $credentials = $this->users->findCredentialsById($owner->id);
        $membership = $this->memberships->findForUser($owner->id);
        if (null === $credentials || null === $membership) {
            // An owner without a hash or a workspace is an interrupted setup,
            // which `make setup` completes.
            throw new OwnerAccountNotProvisioned();
        }

        $newPassword = $this->policy->choose($input->newPassword);
        $this->policy->assertDiffersFromHash($credentials->passwordHash, $input->newPassword);

        $newHash = $this->hasher->hash($newPassword);

        $this->transactionManager->transactional(function () use ($owner, $credentials, $membership, $newHash): void {
            // Compare-and-set: a concurrent change keeps its own password.
            if (!$this->passwords->replaceHash($owner->id, $credentials->passwordHash, $newHash)) {
                throw new \RuntimeException('The owner password changed during the reset; run it again.');
            }

            $this->sessions->revokeAllExcept(null);

            // Empty diff on purpose: nothing about a credential is recorded.
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $membership->workspace,
                actorId: null,
                eventType: IdentityAuditEvents::PASSWORD_RESET,
                entityType: IdentityAuditEvents::ENTITY_USER,
                entityId: $owner->id,
                diff: AuditDiff::none(),
            ));
        });
    }
}
