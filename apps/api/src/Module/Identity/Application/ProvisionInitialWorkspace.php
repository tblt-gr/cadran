<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Identity\Domain\Membership;
use App\Module\Identity\Domain\MembershipRepository;
use App\Module\Identity\Domain\User;
use App\Module\Identity\Domain\UserRepository;
use App\Module\Identity\Domain\Workspace;
use App\Module\Identity\Domain\WorkspaceRepository;

final readonly class ProvisionInitialWorkspace
{
    public function __construct(
        private TransactionManager $transactionManager,
        private InitialProvisioningGuard $provisioningGuard,
        private UserRepository $userRepository,
        private WorkspaceRepository $workspaceRepository,
        private MembershipRepository $membershipRepository,
        private UuidGenerator $uuidGenerator,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(InitialWorkspaceProvisioningInput $input): InitialWorkspaceProvisioningResult
    {
        // Build and validate the aggregate before opening the transaction, so a
        // malformed input never claims the one-time provisioning latch.
        $createdAt = new \DateTimeImmutable();
        $user = new User(
            id: $this->uuidGenerator->generate(),
            email: mb_strtolower(trim($input->email)),
            displayName: trim($input->displayName),
            createdAt: $createdAt,
        );
        $workspace = new Workspace(
            id: $this->uuidGenerator->generate(),
            name: trim($input->workspaceName),
            timezone: Workspace::DEFAULT_TIMEZONE,
            baseCurrency: trim($input->baseCurrency),
            createdAt: $createdAt,
        );
        $membership = new Membership(
            id: $this->uuidGenerator->generate(),
            workspaceId: $workspace->id,
            userId: $user->id,
            role: Membership::OWNER,
            createdAt: $createdAt,
        );

        return $this->transactionManager->transactional(function () use ($user, $workspace, $membership): InitialWorkspaceProvisioningResult {
            if (!$this->provisioningGuard->tryAcquire()) {
                throw new InitialProvisioningAlreadyCompleted();
            }

            $this->userRepository->save($user);
            $this->workspaceRepository->save($workspace);
            $this->membershipRepository->save($membership);
            $this->audit($user, $workspace, $membership);

            return new InitialWorkspaceProvisioningResult($workspace->id);
        });
    }

    /**
     * Recorded inside the provisioning transaction: an install either has an
     * owner and its trail, or neither. No email reaches the diff — the entity
     * identifier already names the account, and the trail is not a second copy
     * of the identity record.
     */
    private function audit(User $user, Workspace $workspace, Membership $membership): void
    {
        foreach ([
            new AuditEventRecord(
                workspaceId: $workspace->id,
                actorId: null,
                eventType: IdentityAuditEvents::WORKSPACE_CREATED,
                entityType: IdentityAuditEvents::ENTITY_WORKSPACE,
                entityId: $workspace->id,
                diff: AuditDiff::creation([
                    'name' => $workspace->name,
                    'baseCurrency' => $workspace->baseCurrency,
                    'timezone' => $workspace->timezone,
                ]),
            ),
            new AuditEventRecord(
                workspaceId: $workspace->id,
                actorId: null,
                eventType: IdentityAuditEvents::USER_CREATED,
                entityType: IdentityAuditEvents::ENTITY_USER,
                entityId: $user->id,
                diff: AuditDiff::creation(['displayName' => $user->displayName]),
            ),
            new AuditEventRecord(
                workspaceId: $workspace->id,
                actorId: null,
                eventType: IdentityAuditEvents::MEMBERSHIP_GRANTED,
                entityType: IdentityAuditEvents::ENTITY_MEMBERSHIP,
                entityId: $membership->id,
                diff: AuditDiff::creation(['role' => $membership->role]),
            ),
        ] as $record) {
            ($this->recordAuditEvent)($record);
        }
    }
}
