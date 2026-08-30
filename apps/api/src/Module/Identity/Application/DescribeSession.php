<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * Builds the session view for GET /api/v1/session. The caller passes the
 * authenticated identifier resolved by the firewall, or null when the request
 * is anonymous.
 */
final readonly class DescribeSession
{
    public function __construct(
        private AuthenticationUserRepository $users,
        private WorkspaceMembershipReader $memberships,
    ) {
    }

    public function __invoke(?string $authenticatedEmail): SessionView
    {
        $owner = $this->users->findProvisionedOwner();
        $provisioned = null !== $owner;
        $setupRequired = $provisioned && !$owner->hasPassword;

        if (null === $authenticatedEmail) {
            return SessionView::anonymous($provisioned, $setupRequired);
        }

        $user = $this->users->findByEmail($authenticatedEmail);
        if (null === $user || !$user->hasPassword || $user->isDisabled()) {
            // The session points at a user that has since been removed, has no
            // usable credentials, or was disabled mid-session: treat it as not
            // authenticated so the browser drops back to the login screen.
            return SessionView::anonymous($provisioned, $setupRequired);
        }

        $membership = $this->memberships->findForUser($user->id);

        return new SessionView(
            provisioned: true,
            authenticated: true,
            setupRequired: false,
            user: new SessionUser($user->id, $user->email, $user->displayName),
            workspace: null === $membership
                ? null
                : new SessionWorkspace($membership->workspaceId, $membership->role),
        );
    }
}
