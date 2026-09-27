<?php

declare(strict_types=1);

namespace App\Module\Demo\Infrastructure;

use App\Module\Demo\Application\DemoActor;
use App\Module\Demo\Application\DemoSeedRefused;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final readonly class TokenDemoActor implements DemoActor
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
            throw new DemoSeedRefused('No enabled owner was found: run the local setup first or check the email.');
        }

        // The hash is never compared: the token only carries the identity that
        // the workspace resolver reads back.
        $this->tokens->setToken(new UsernamePasswordToken(new SecurityUser($user->email, 'demo-console-session', false), 'main'));
    }
}
