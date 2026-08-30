<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * Non-credential view of a user, for session description and the first-run
 * check. The password hash is deliberately absent: it lives only on
 * {@see OwnerCredentials}, which never leaves the security layer.
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public bool $hasPassword,
        public ?\DateTimeImmutable $disabledAt,
    ) {
    }

    public function isDisabled(): bool
    {
        return null !== $this->disabledAt;
    }
}
