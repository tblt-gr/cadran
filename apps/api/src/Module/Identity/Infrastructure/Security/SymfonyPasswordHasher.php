<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security;

use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsAlias(PasswordHasher::class)]
final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    public function __construct(private PasswordHasherFactoryInterface $factory)
    {
    }

    public function hash(PlainPassword $password): string
    {
        // Keyed by SecurityUser so this matches the algorithm the firewall uses
        // to verify the same password at login (Argon2id, config/packages/security.yaml).
        return $this->factory->getPasswordHasher(SecurityUser::class)->hash($password->value);
    }
}
