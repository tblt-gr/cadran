<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;
use App\Module\Identity\Application\WorkspaceMembership;
use App\Module\Identity\Application\WorkspaceMembershipReader;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(WorkspaceMembershipReader::class)]
final readonly class DbalWorkspaceMembershipReader implements WorkspaceMembershipReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function findForUser(string $userId): ?WorkspaceMembership
    {
        $row = $this->connection->fetchAssociative(
            'SELECT workspace_id, role FROM identity_workspace_memberships WHERE user_id = ?',
            [$userId],
        );

        return false === $row
            ? null
            : new WorkspaceMembership(
                WorkspaceScope::fromString(DbalValue::text($row['workspace_id'] ?? null)),
                DbalValue::text($row['role'] ?? null),
            );
    }
}
