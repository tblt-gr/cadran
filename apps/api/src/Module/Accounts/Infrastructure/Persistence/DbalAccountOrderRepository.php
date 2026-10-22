<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\AccountOrderRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AccountOrderRepository::class)]
final readonly class DbalAccountOrderRepository implements AccountOrderRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function lockVersion(WorkspaceScope $workspace, ?string $groupId): int
    {
        $parameters = ['workspace_id' => $workspace->id, 'group_key' => AccountOrdering::key($groupId)];
        $this->connection->executeStatement(
            'INSERT INTO account_overview_orders (workspace_id, group_key, version) VALUES (:workspace_id, :group_key, 0)'
            .' ON CONFLICT (workspace_id, group_key) DO NOTHING',
            $parameters,
        );

        return (int) AccountRow::text($this->connection->fetchOne(
            'SELECT version FROM account_overview_orders WHERE workspace_id = :workspace_id AND group_key = :group_key FOR UPDATE',
            $parameters,
        ));
    }

    public function bump(WorkspaceScope $workspace, ?string $groupId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO account_overview_orders (workspace_id, group_key, version) VALUES (:workspace_id, :group_key, 1)'
            .' ON CONFLICT (workspace_id, group_key) DO UPDATE SET version = account_overview_orders.version + 1',
            ['workspace_id' => $workspace->id, 'group_key' => AccountOrdering::key($groupId)],
        );
    }

    public function version(WorkspaceScope $workspace, ?string $groupId): int
    {
        $version = $this->connection->fetchOne(
            'SELECT version FROM account_overview_orders WHERE workspace_id = :workspace_id AND group_key = :group_key',
            ['workspace_id' => $workspace->id, 'group_key' => AccountOrdering::key($groupId)],
        );

        return false === $version ? 0 : (int) AccountRow::text($version);
    }

    public function versions(WorkspaceScope $workspace): array
    {
        $versions = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT group_key, version FROM account_overview_orders WHERE workspace_id = :workspace_id AND version > 0',
            ['workspace_id' => $workspace->id],
        ) as $row) {
            $versions[AccountRow::text($row['group_key'] ?? null)] = (int) AccountRow::text($row['version'] ?? null);
        }

        return $versions;
    }

    public function save(WorkspaceScope $workspace, ?string $groupId, array $orderedAccountIds, int $newVersion): void
    {
        $key = AccountOrdering::key($groupId);
        foreach ($orderedAccountIds as $index => $accountId) {
            $this->connection->executeStatement(
                'INSERT INTO account_overview_positions (workspace_id, account_id, group_key, position)'
                .' VALUES (:workspace_id, :account_id, :group_key, :position)'
                .' ON CONFLICT (workspace_id, account_id) DO UPDATE SET group_key = EXCLUDED.group_key, position = EXCLUDED.position',
                ['workspace_id' => $workspace->id, 'account_id' => $accountId, 'group_key' => $key, 'position' => $index + 1],
            );
        }

        $this->connection->executeStatement(
            'INSERT INTO account_overview_orders (workspace_id, group_key, version) VALUES (:workspace_id, :group_key, :version)'
            .' ON CONFLICT (workspace_id, group_key) DO UPDATE SET version = EXCLUDED.version',
            ['workspace_id' => $workspace->id, 'group_key' => $key, 'version' => $newVersion],
        );
    }
}
