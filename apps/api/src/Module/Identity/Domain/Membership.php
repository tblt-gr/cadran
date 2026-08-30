<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

final readonly class Membership
{
    public const string OWNER = 'OWNER';

    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $userId,
        public string $role,
        public \DateTimeImmutable $createdAt,
    ) {
        if ('' === $id || '' === $workspaceId || '' === $userId) {
            throw new \InvalidArgumentException('A membership requires an identifier, a workspace, and a user.');
        }

        if (self::OWNER !== $role) {
            throw new \InvalidArgumentException(sprintf('Unsupported membership role "%s".', $role));
        }
    }
}
