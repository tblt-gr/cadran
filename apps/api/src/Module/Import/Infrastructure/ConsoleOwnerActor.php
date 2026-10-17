<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure;

use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Import\Application\ImportActor;
use App\Module\Import\Domain\WorkbookUsageError;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Sets the security token of the owner, exactly as the demo seed does, so the workspace resolves through the normal caller context. */
final readonly class ConsoleOwnerActor implements ImportActor
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private AuthenticationUserRepository $users,
    ) {
    }

    public function assume(?string $email): void
    {
        $user = null === $email ? $this->users->findProvisionedOwner() : $this->users->findByEmail($email);
        if (null === $user || !$user->hasPassword || $user->isDisabled()) {
            // Forget a previous owner: a refused email must never leave the console acting as someone else.
            $this->tokens->setToken(null);
            throw new WorkbookUsageError('No enabled owner was found: run the local setup first or check the email.');
        }

        // The hash is never compared: the token only carries the identity that the workspace resolver reads back.
        $this->tokens->setToken(new UsernamePasswordToken(new SecurityUser($user->email, 'import-console-session', false), 'main'));
    }
}
