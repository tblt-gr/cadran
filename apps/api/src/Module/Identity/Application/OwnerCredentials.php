<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * Credential-bearing view used only by the firewall's user provider. Carries
 * the Argon2id hash; must never be serialized or returned by a controller.
 */
final readonly class OwnerCredentials
{
    public function __construct(
        public string $email,
        public string $passwordHash,
        public ?\DateTimeImmutable $disabledAt,
    ) {
    }

    public function isDisabled(): bool
    {
        return null !== $this->disabledAt;
    }
}
