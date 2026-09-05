<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountGroupConflict;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AccountGroupRepository::class)]
final readonly class DbalAccountGroupRepository implements AccountGroupRepository
{
    private const string COLUMNS = 'id, workspace_id, label, parent_id, sort_order, depth, version, created_at, updated_at, archived_at';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?AccountGroup
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_groups WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : AccountGroupRow::hydrate($row, $workspace);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?AccountGroup
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_groups WHERE workspace_id = :workspace_id AND id = :id FOR UPDATE',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : AccountGroupRow::hydrate($row, $workspace);
    }

    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array
    {
        [$where, $parameters] = self::filters($workspace, $includeArchived);
        $parameters['limit'] = $limit;
        $parameters['offset'] = $offset;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_groups WHERE workspace_id = :workspace_id AND ('.$where.')'
            .' ORDER BY depth, sort_order, normalized_label, id LIMIT :limit OFFSET :offset',
            $parameters,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): AccountGroup => AccountGroupRow::hydrate($row, $workspace), $rows);
    }

    public function count(WorkspaceScope $workspace, bool $includeArchived): int
    {
        [$where, $parameters] = self::filters($workspace, $includeArchived);

        return (int) AccountGroupRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM account_groups WHERE workspace_id = :workspace_id AND ('.$where.')',
            $parameters,
        ));
    }

    public function hasActiveSiblingLabel(
        WorkspaceScope $workspace,
        ?string $parentId,
        string $label,
        ?string $excludingId = null,
    ): bool {
        $excludeClause = null === $excludingId ? '' : ' AND id <> :excluding_id';
        $parameters = [
            'workspace_id' => $workspace->id,
            'parent_id' => $parentId,
            'label' => $label,
        ];
        if (null !== $excludingId) {
            $parameters['excluding_id'] = $excludingId;
        }

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM account_groups'
            .' WHERE workspace_id = :workspace_id'
            .' AND parent_id IS NOT DISTINCT FROM :parent_id AND normalized_label = lower(btrim(:label))'
            .' AND archived_at IS NULL'.$excludeClause.' LIMIT 1',
            $parameters,
        );
    }

    public function hasChildren(WorkspaceScope $workspace, string $id): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM account_groups'
            .' WHERE workspace_id = :workspace_id AND parent_id = :parent_id'
            .' AND archived_at IS NULL LIMIT 1',
            ['workspace_id' => $workspace->id, 'parent_id' => $id],
        );
    }

    public function descendantsForUpdate(WorkspaceScope $workspace, string $ancestorId): array
    {
        $found = [];
        $frontier = [$ancestorId];
        while ([] !== $frontier) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT '.self::COLUMNS.' FROM account_groups'
                .' WHERE workspace_id = :workspace_id AND parent_id IN (:ids) FOR UPDATE',
                ['workspace_id' => $workspace->id, 'ids' => $frontier],
                ['ids' => ArrayParameterType::STRING],
            );
            $frontier = [];
            foreach ($rows as $row) {
                $group = AccountGroupRow::hydrate($row, $workspace);
                $found[] = $group;
                $frontier[] = $group->id;
            }
        }

        return $found;
    }

    public function isReferencedByAccount(WorkspaceScope $workspace, string $id): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM account_financial_accounts'
            .' WHERE workspace_id = :workspace_id AND primary_group_id = :group_id LIMIT 1',
            ['workspace_id' => $workspace->id, 'group_id' => $id],
        ) || false !== $this->connection->fetchOne(
            'SELECT 1 FROM account_group_tags'
            .' WHERE workspace_id = :workspace_id AND group_id = :group_id LIMIT 1',
            ['workspace_id' => $workspace->id, 'group_id' => $id],
        );
    }

    public function parentIdsWithChildren(WorkspaceScope $workspace, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $values = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT parent_id FROM account_groups'
            .' WHERE workspace_id = :workspace_id AND parent_id IN (:ids) AND archived_at IS NULL',
            ['workspace_id' => $workspace->id, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(AccountGroupRow::text(...), $values);
    }

    public function labelsByIds(WorkspaceScope $workspace, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, label FROM account_groups WHERE workspace_id = :workspace_id AND id IN (:ids)',
            ['workspace_id' => $workspace->id, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $labels = [];
        foreach ($rows as $row) {
            $labels[AccountGroupRow::text($row['id'])] = AccountGroupRow::text($row['label']);
        }

        return $labels;
    }

    public function add(AccountGroup $group): void
    {
        try {
            $this->connection->insert('account_groups', [
                'id' => $group->id,
                'workspace_id' => $group->workspace->id,
                ...AccountGroupRow::columns($group),
                'created_at' => $group->createdAt->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new AccountGroupConflict('An active sibling already uses this label.', previous: $exception);
        }
    }

    public function update(AccountGroup $group, int $expectedVersion): bool
    {
        try {
            return 1 === (int) $this->connection->update(
                'account_groups',
                AccountGroupRow::columns($group),
                [
                    'workspace_id' => $group->workspace->id,
                    'id' => $group->id,
                    'version' => $expectedVersion,
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new AccountGroupConflict('An active sibling already uses this label.', previous: $exception);
        }
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function filters(WorkspaceScope $workspace, bool $includeArchived): array
    {
        $conditions = ['workspace_id = :workspace_id'];
        $parameters = ['workspace_id' => $workspace->id];
        if (!$includeArchived) {
            $conditions[] = 'archived_at IS NULL';
        }

        return [implode(' AND ', $conditions), $parameters];
    }
}
