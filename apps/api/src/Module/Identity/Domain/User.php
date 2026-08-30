<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

final readonly class User
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public \DateTimeImmutable $createdAt,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A user requires an identifier.');
        }

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new \InvalidArgumentException('A user requires a valid email address of at most 254 characters.');
        }

        if ('' === trim($displayName) || mb_strlen($displayName) > 100) {
            throw new \InvalidArgumentException('A user display name must be between 1 and 100 characters.');
        }
    }
}
