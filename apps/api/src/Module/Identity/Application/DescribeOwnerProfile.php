<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;

/**
 * Reads the authenticated owner's own account for the settings screen. It
 * answers from the identity record alone, so the profile section renders on an
 * install that holds no account, transaction or valuation yet.
 */
final readonly class DescribeOwnerProfile
{
    public function __construct(
        private CallerWorkspaceContext $callerContext,
        private AuthenticationUserRepository $users,
    ) {
    }

    public function __invoke(): OwnerProfileView
    {
        $context = $this->callerContext->resolveContext();

        $user = $this->users->findById($context->actorId);
        if (null === $user) {
            throw new OwnerAccountNotProvisioned();
        }

        return new OwnerProfileView($user->id, $user->email, $user->displayName);
    }
}
