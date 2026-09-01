<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;

/**
 * First-run flow: set the provisioned owner's password exactly once. The write
 * is a compare-and-set on password_hash IS NULL, so a replayed or concurrent
 * request cannot overwrite an existing password.
 */
final readonly class DefineInitialPassword
{
    public function __construct(
        private TransactionManager $transactionManager,
        private AuthenticationUserRepository $users,
        private WorkspaceMembershipReader $memberships,
        private OwnerPasswordWriter $passwords,
        private PasswordHasher $hasher,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(DefineInitialPasswordInput $input): void
    {
        $owner = $this->users->findProvisionedOwner();
        if (null === $owner) {
            throw new OwnerAccountNotProvisioned();
        }

        if ($owner->hasPassword) {
            throw new InitialPasswordAlreadyDefined();
        }

        // A provisioned owner always has a workspace: ProvisionInitialWorkspace
        // creates both in one transaction. Without one there is no scope to
        // audit into, and the install is not usable anyway.
        $membership = $this->memberships->findForUser($owner->id);
        if (null === $membership) {
            throw new OwnerAccountNotProvisioned();
        }

        // Validate before hashing so a rejected policy check does no work.
        $password = PlainPassword::fromString($input->plainPassword);
        $hash = $this->hasher->hash($password);

        $this->transactionManager->transactional(function () use ($owner, $hash, $membership): void {
            if (!$this->passwords->storeInitialHash($owner->id, $hash)) {
                throw new InitialPasswordAlreadyDefined();
            }

            // The request that sets the first password is necessarily
            // unauthenticated, so the event has no actor; the entity identifies
            // the account. The diff is empty on purpose — recording anything
            // about a credential, even its length, is a leak.
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $membership->workspace,
                actorId: null,
                eventType: IdentityAuditEvents::PASSWORD_DEFINED,
                entityType: IdentityAuditEvents::ENTITY_USER,
                entityId: $owner->id,
                diff: AuditDiff::none(),
            ));
        });
    }
}
