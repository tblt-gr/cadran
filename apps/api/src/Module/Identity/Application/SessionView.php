<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * What the browser needs to render the auth boundary:
 * - provisioned: an owner account exists (IDN-001 has run)
 * - authenticated: a valid, active session is attached to this request
 * - setupRequired: the provisioned owner has no password yet (first-run flow)
 * - user / workspace: present only when authenticated
 */
final readonly class SessionView
{
    public function __construct(
        public bool $provisioned,
        public bool $authenticated,
        public bool $setupRequired,
        public ?SessionUser $user,
        public ?SessionWorkspace $workspace,
    ) {
    }

    public static function anonymous(bool $provisioned, bool $setupRequired): self
    {
        return new self($provisioned, false, $setupRequired, null, null);
    }
}
