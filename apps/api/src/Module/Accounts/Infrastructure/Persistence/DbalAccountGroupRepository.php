<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountGroupConflict;
use App\Module\Accounts\Application\InvalidAccountGroupInput;
use App\Module\Accounts\Application\MoveAccountGroup;
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
    private const string COLUMNS = 'id, workspace_id, label, color, color_mode, color_slot, parent_id, sort_order, depth, version, created_at, updated_at, archived_at';

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

    public function usedColorSlots(WorkspaceScope $workspace, ?string $parentId): array
    {
        $values = $this->connection->fetchFirstColumn(
            'SELECT color_slot FROM account_groups'
            .' WHERE workspace_id = :workspace_id AND parent_id IS NOT DISTINCT FROM :parent_id',
            ['workspace_id' => $workspace->id, 'parent_id' => $parentId],
        );

        return array_map(static fn (mixed $value): int => (int) AccountGroupRow::text($value), $values);
    }

    public function isReferencedByAccount(WorkspaceScope $workspace, string $id): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM account_financial_accounts'
            .' WHERE workspace_id = :workspace_id AND primary_group_id = :group_id LIMIT 1',
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

    public function lockSiblings(WorkspaceScope $workspace, ?string $parentId, int $limit): array
    {
        $this->lockSiblingRows($workspace, $parentId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_groups'
            .' WHERE workspace_id = :workspace_id AND parent_id IS NOT DISTINCT FROM :parent_id'
            .' ORDER BY sort_order, normalized_label, id LIMIT :limit',
            ['workspace_id' => $workspace->id, 'parent_id' => $parentId, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): AccountGroup => AccountGroupRow::hydrate($row, $workspace), $rows);
    }

    public function saveSiblingOrder(WorkspaceScope $workspace, ?string $parentId, array $orderedIds): void
    {
        if ([] === $orderedIds) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE account_groups AS g SET sort_order = ordered.position - 1'
            .' FROM unnest(CAST(:ids AS uuid[])) WITH ORDINALITY AS ordered(id, position)'
            .' WHERE g.id = ordered.id AND g.workspace_id = :workspace_id AND g.parent_id IS NOT DISTINCT FROM :parent_id',
            [
                'ids' => '{'.implode(',', $orderedIds).'}',
                'workspace_id' => $workspace->id,
                'parent_id' => $parentId,
            ],
        );
    }

    public function appendPosition(WorkspaceScope $workspace, ?string $parentId): int
    {
        $this->lockSiblingRows($workspace, $parentId);
        $next = (int) AccountGroupRow::text($this->connection->fetchOne(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM account_groups'
            .' WHERE workspace_id = :workspace_id AND parent_id IS NOT DISTINCT FROM :parent_id',
            ['workspace_id' => $workspace->id, 'parent_id' => $parentId],
        ));
        if ($next <= AccountGroup::MAX_SORT_ORDER) {
            return $next;
        }

        $siblings = $this->lockSiblings($workspace, $parentId, MoveAccountGroup::MAX_SIBLINGS + 1);
        if (count($siblings) > MoveAccountGroup::MAX_SIBLINGS) {
            throw new InvalidAccountGroupInput('This parent holds too many groups to renumber their order.');
        }

        $this->saveSiblingOrder($workspace, $parentId, array_map(static fn (AccountGroup $group): string => $group->id, $siblings));

        return count($siblings);
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

    /** Id order, so two writers on the same parent never deadlock. */
    private function lockSiblingRows(WorkspaceScope $workspace, ?string $parentId): void
    {
        $this->connection->fetchFirstColumn(
            'SELECT id FROM account_groups'
            .' WHERE workspace_id = :workspace_id AND parent_id IS NOT DISTINCT FROM :parent_id ORDER BY id FOR UPDATE',
            ['workspace_id' => $workspace->id, 'parent_id' => $parentId],
        );
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
