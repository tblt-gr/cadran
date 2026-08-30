<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Domain\Workspace;
use App\Module\Identity\Domain\WorkspaceRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(WorkspaceRepository::class)]
final readonly class DbalWorkspaceRepository implements WorkspaceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(Workspace $workspace): void
    {
        $this->connection->insert('identity_workspaces', [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'timezone' => $workspace->timezone,
            'base_currency' => $workspace->baseCurrency,
            'created_at' => $workspace->createdAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
