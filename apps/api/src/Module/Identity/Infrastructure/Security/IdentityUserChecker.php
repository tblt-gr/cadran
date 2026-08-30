<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class IdentityUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // Checked only after the password is verified, so probing an email never
        // reveals that a disabled account exists. A plain DisabledException would
        // be folded into "bad credentials" by the authenticator's enumeration
        // guard; the custom-message variant is exempt, letting the failure
        // handler answer 403 to a caller who already proved the password.
        if ($user instanceof SecurityUser && $user->isDisabled()) {
            throw new CustomUserMessageAccountStatusException('Account disabled.');
        }
    }
}
