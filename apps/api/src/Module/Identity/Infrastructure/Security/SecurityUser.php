<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Firewall-facing view of the owner account. The identifier is the lowercased
 * email. Only the password hash is carried, never a plaintext credential.
 */
final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private readonly string $identifier,
        private readonly string $passwordHash,
        private readonly bool $disabled,
    ) {
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->identifier) {
            throw new \LogicException('A security user requires a non-empty identifier.');
        }

        return $this->identifier;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // No plaintext credential is ever stored on this object.
    }
}
