<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;

/**
 * Replaces the authenticated owner's password after re-proving the current one.
 *
 * The three refusals are ordered by cost and by what they reveal: the policy
 * check is free and tells an attacker nothing, the current-password check is
 * the expensive Argon2id verification, and the reuse check compares the two
 * plaintexts the caller already holds. Nothing is written until all three pass,
 * so a rejected request leaves the stored hash and the open sessions untouched.
 */
final readonly class ChangeOwnerPassword
{
    public function __construct(
        private TransactionManager $transactionManager,
        private CallerWorkspaceContext $callerContext,
        private AuthenticationUserRepository $users,
        private OwnerPasswordWriter $passwords,
        private OwnerSessionRegistry $sessions,
        private CurrentSessionRenewal $currentSession,
        private PasswordHasher $hasher,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(ChangeOwnerPasswordInput $input): void
    {
        $context = $this->callerContext->resolveContext();

        // Validate the candidate before touching a credential: a malformed
        // request must not cost an Argon2id verification, which is the very
        // work a brute-force attempt is trying to make the server do.
        $newPassword = PlainPassword::fromString($input->newPassword);

        $credentials = $this->users->findCredentialsById($context->actorId);
        if (null === $credentials) {
            // The firewall authenticated this caller, so the account existed a
            // moment ago; it has since been deleted or stripped of its hash.
            throw new OwnerAccountNotProvisioned();
        }

        if (!$this->hasher->verify($credentials->passwordHash, $input->currentPassword)) {
            // Recorded like a failed sign-in, and for the same reason: a burst
            // of guesses against a hijacked session is the signal the owner
            // needs when they read the trail. No credential reaches the event.
            $this->auditFailedAttempt($context);

            throw new InvalidCurrentPassword();
        }

        // Both plaintexts are in hand and the previous check proved the first
        // one is the account's current password, so equality is a constant-time
        // string comparison rather than a second Argon2id verification.
        if (hash_equals($input->currentPassword, $input->newPassword)) {
            throw new NewPasswordReused();
        }

        $newHash = $this->hasher->hash($newPassword);

        $this->transactionManager->transactional(function () use ($context, $credentials, $newHash, $input): void {
            // Compare-and-set on the hash the caller just verified: a change
            // that landed in between keeps its own password rather than being
            // silently overwritten by this one.
            if (!$this->passwords->replaceHash($context->actorId, $credentials->passwordHash, $newHash)) {
                throw new InvalidCurrentPassword();
            }

            // Inside the transaction: a failed audit write or a failed session
            // revocation must not leave a new password behind that the owner
            // believes revoked nothing.
            $this->sessions->revokeAllExcept($input->sessionIdToKeep);

            // The diff is empty on purpose. Recording anything about a
            // credential, even its length, is a leak; the event type and the
            // entity already say which account changed its password.
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: IdentityAuditEvents::PASSWORD_CHANGED,
                entityType: IdentityAuditEvents::ENTITY_USER,
                entityId: $context->actorId,
                diff: AuditDiff::none(),
            ));
        });

        // After the commit, never before: a rolled-back change must leave the
        // caller on the session it arrived with.
        $this->currentSession->renew();
    }

    private function auditFailedAttempt(WorkspaceContext $context): void
    {
        $this->transactionManager->transactional(function () use ($context): void {
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: IdentityAuditEvents::PASSWORD_CHANGE_FAILED,
                entityType: IdentityAuditEvents::ENTITY_USER,
                entityId: $context->actorId,
                diff: AuditDiff::none(),
            ));
        });
    }
}
