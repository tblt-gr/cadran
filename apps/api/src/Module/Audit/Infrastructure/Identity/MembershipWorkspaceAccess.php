<?php

declare(strict_types=1);

namespace App\Module\Audit\Infrastructure\Identity;

use App\Module\Audit\Application\WorkspaceAccess;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\WorkspaceMembershipReader;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Anti-corruption layer between the audit read side and the identity module:
 * the firewall authenticates an email, identity turns it into a user and its
 * membership, and only the resulting workspace crosses back.
 */
#[AsAlias(WorkspaceAccess::class)]
final readonly class MembershipWorkspaceAccess implements WorkspaceAccess
{
    public function __construct(
        private AuthenticationUserRepository $users,
        private WorkspaceMembershipReader $memberships,
    ) {
    }

    public function readableWorkspaceFor(string $userIdentifier): ?string
    {
        $user = $this->users->findByEmail($userIdentifier);
        if (null === $user || $user->isDisabled()) {
            return null;
        }

        return $this->memberships->findForUser($user->id)?->workspaceId;
    }
}
