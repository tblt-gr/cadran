<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Domain\Membership;
use App\Module\Identity\Domain\MembershipRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MembershipRepository::class)]
final readonly class DbalMembershipRepository implements MembershipRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(Membership $membership): void
    {
        $this->connection->insert('identity_workspace_memberships', [
            'id' => $membership->id,
            'workspace_id' => $membership->workspaceId,
            'user_id' => $membership->userId,
            'role' => $membership->role,
            'created_at' => $membership->createdAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
