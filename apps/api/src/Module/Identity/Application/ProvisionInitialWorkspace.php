<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Identity\Domain\Membership;
use App\Module\Identity\Domain\MembershipRepository;
use App\Module\Identity\Domain\User;
use App\Module\Identity\Domain\UserRepository;
use App\Module\Identity\Domain\UuidGenerator;
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

            return new InitialWorkspaceProvisioningResult($workspace->id);
        });
    }
}
