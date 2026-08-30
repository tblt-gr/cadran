<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Domain\User;
use App\Module\Identity\Domain\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(UserRepository::class)]
final readonly class DbalUserRepository implements UserRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(User $user): void
    {
        $this->connection->insert('identity_users', [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->displayName,
            'created_at' => $user->createdAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
