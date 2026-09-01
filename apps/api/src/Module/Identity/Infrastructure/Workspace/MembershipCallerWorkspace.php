<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Workspace;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\WorkspaceMembershipReader;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Identity's answer to "which workspace is the caller in".
 *
 * It lives in Identity rather than in the shared kernel so every module arrow
 * keeps pointing at Foundation, which owns only the interface. The token is
 * read here rather than passed in: the caller's identity never becomes a
 * parameter another layer could supply.
 */
#[AsAlias(CallerWorkspace::class)]
final readonly class MembershipCallerWorkspace implements CallerWorkspace
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private AuthenticationUserRepository $users,
        private WorkspaceMembershipReader $memberships,
    ) {
    }

    public function resolve(): WorkspaceScope
    {
        $identifier = $this->tokenStorage->getToken()?->getUser()?->getUserIdentifier();
        if (null === $identifier) {
            throw new WorkspaceAccessDenied('An anonymous caller belongs to no workspace.');
        }

        $user = $this->users->findByEmail($identifier);
        // hasPassword is checked here as it is in DescribeSession: a session
        // must not outlive the credential it was opened with, so an account
        // whose password is removed loses its scope on the next request.
        if (null === $user || !$user->hasPassword || $user->isDisabled()) {
            throw new WorkspaceAccessDenied('The caller is unknown, credential-less, or disabled.');
        }

        $membership = $this->memberships->findForUser($user->id);
        if (null === $membership) {
            throw new WorkspaceAccessDenied('The caller belongs to no workspace.');
        }

        return $membership->workspace;
    }
}
