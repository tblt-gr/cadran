<?php

declare(strict_types=1);

namespace App\Module\Categories\Infrastructure\Persistence;

use App\Module\Categories\Application\CategoryConflict;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CategoryRepository::class)]
final readonly class DbalCategoryRepository implements CategoryRepository
{
    private const string COLUMNS = 'id, workspace_id, type, label, parent_id, icon, color, default_analytic_axes, budget_included, sort_order, depth, version, created_at, updated_at, used_at, archived_at';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?Category
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM category_categories WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : self::hydrate($row, $workspace);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Category
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM category_categories WHERE workspace_id = :workspace_id AND id = :id FOR UPDATE',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : self::hydrate($row, $workspace);
    }

    public function list(
        WorkspaceScope $workspace,
        bool $includeArchived,
        int $limit,
        int $offset,
        ?CategoryType $type = null,
        ?string $search = null,
        bool $parentEligible = false,
    ): array {
        [$where, $parameters] = self::filters($workspace, $includeArchived, $type, $search, $parentEligible);
        $parameters['limit'] = $limit;
        $parameters['offset'] = $offset;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM category_categories WHERE workspace_id = :workspace_id AND ('.$where.')'
            .' ORDER BY type, depth, sort_order, normalized_label, id LIMIT :limit OFFSET :offset',
            $parameters,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): Category => self::hydrate($row, $workspace), $rows);
    }

    public function count(
        WorkspaceScope $workspace,
        bool $includeArchived,
        ?CategoryType $type = null,
        ?string $search = null,
        bool $parentEligible = false,
    ): int {
        [$where, $parameters] = self::filters($workspace, $includeArchived, $type, $search, $parentEligible);

        return (int) self::scalar($this->connection->fetchOne(
            'SELECT count(*) FROM category_categories WHERE workspace_id = :workspace_id AND ('.$where.')',
            $parameters,
        ));
    }

    public function hasActiveSiblingLabel(
        WorkspaceScope $workspace,
        CategoryType $type,
        ?string $parentId,
        string $label,
        ?string $excludingId = null,
    ): bool {
        $excludeClause = null === $excludingId ? '' : ' AND id <> :excluding_id';
        $parameters = [
            'workspace_id' => $workspace->id,
            'type' => $type->value,
            'parent_id' => $parentId,
            'label' => $label,
        ];
        if (null !== $excludingId) {
            $parameters['excluding_id'] = $excludingId;
        }

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM category_categories'
            .' WHERE workspace_id = :workspace_id AND type = :type'
            .' AND parent_id IS NOT DISTINCT FROM :parent_id AND normalized_label = lower(btrim(:label))'
            .' AND archived_at IS NULL'.$excludeClause.' LIMIT 1',
            $parameters,
        );
    }

    public function hasChildren(WorkspaceScope $workspace, string $id): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM category_categories WHERE workspace_id = :workspace_id AND parent_id = :parent_id LIMIT 1',
            ['workspace_id' => $workspace->id, 'parent_id' => $id],
        );
    }

    public function parentIdsWithChildren(WorkspaceScope $workspace, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $values = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT parent_id FROM category_categories WHERE workspace_id = :workspace_id AND parent_id IN (:ids)',
            ['workspace_id' => $workspace->id, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(self::scalar(...), $values);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    public function labelsByIds(WorkspaceScope $workspace, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, label FROM category_categories WHERE workspace_id = :workspace_id AND id IN (:ids)',
            ['workspace_id' => $workspace->id, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $labels = [];
        foreach ($rows as $row) {
            $labels[self::scalar($row['id'])] = self::scalar($row['label']);
        }

        return $labels;
    }

    public function add(Category $category): void
    {
        try {
            $this->connection->insert('category_categories', [
                'id' => $category->id,
                'workspace_id' => $category->workspace->id,
                ...self::values($category),
                'created_at' => $category->createdAt->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new CategoryConflict('An active sibling already uses this label.', previous: $exception);
        }
    }

    public function update(Category $category, int $expectedVersion): bool
    {
        try {
            return 1 === (int) $this->connection->update(
                'category_categories',
                self::values($category),
                [
                    'workspace_id' => $category->workspace->id,
                    'id' => $category->id,
                    'version' => $expectedVersion,
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new CategoryConflict('An active sibling already uses this label.', previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(Category $category): array
    {
        return [
            'type' => $category->type->value,
            'label' => $category->label,
            'parent_id' => $category->parentId,
            'icon' => $category->icon,
            'color' => $category->color,
            'default_analytic_axes' => json_encode(
                array_map(static fn (AnalyticAxis $axis): string => $axis->value, $category->defaultAnalyticAxes),
                JSON_THROW_ON_ERROR,
            ),
            'budget_included' => $category->budgetIncluded,
            'sort_order' => $category->sortOrder,
            'depth' => $category->depth,
            'version' => $category->version,
            'updated_at' => $category->updatedAt->format('Y-m-d H:i:s.uP'),
            'used_at' => $category->usedAt?->format('Y-m-d H:i:s.uP'),
            'archived_at' => $category->archivedAt?->format('Y-m-d H:i:s.uP'),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row, WorkspaceScope $workspace): Category
    {
        if ($workspace->id !== self::scalar($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A category row escaped its requested workspace.');
        }
        $axes = json_decode(self::scalar($row['default_analytic_axes'] ?? null), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($axes)) {
            throw new \UnexpectedValueException('Expected category axes to be an array.');
        }

        return new Category(
            id: self::scalar($row['id'] ?? null),
            workspace: $workspace,
            type: CategoryType::from(self::scalar($row['type'] ?? null)),
            label: self::scalar($row['label'] ?? null),
            parentId: self::nullableScalar($row['parent_id'] ?? null),
            icon: self::nullableScalar($row['icon'] ?? null),
            color: self::nullableScalar($row['color'] ?? null),
            defaultAnalyticAxes: array_map(
                static fn (mixed $axis): AnalyticAxis => AnalyticAxis::from(self::scalar($axis)),
                array_values($axes),
            ),
            budgetIncluded: self::boolean($row['budget_included'] ?? null),
            sortOrder: (int) self::scalar($row['sort_order'] ?? null),
            depth: (int) self::scalar($row['depth'] ?? null),
            version: (int) self::scalar($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(self::scalar($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(self::scalar($row['updated_at'] ?? null)),
            usedAt: self::date($row['used_at'] ?? null),
            archivedAt: self::date($row['archived_at'] ?? null),
        );
    }

    private static function scalar(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    private static function nullableScalar(mixed $value): ?string
    {
        return null === $value ? null : self::scalar($value);
    }

    private static function boolean(mixed $value): bool
    {
        return match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => throw new \UnexpectedValueException('Expected a boolean database value.'),
        };
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable(self::scalar($value));
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function filters(
        WorkspaceScope $workspace,
        bool $includeArchived,
        ?CategoryType $type,
        ?string $search,
        bool $parentEligible,
    ): array {
        $conditions = ['workspace_id = :workspace_id'];
        $parameters = ['workspace_id' => $workspace->id];

        if (!$includeArchived || $parentEligible) {
            $conditions[] = 'archived_at IS NULL';
        }
        if (null !== $type) {
            $conditions[] = 'type = :type';
            $parameters['type'] = $type->value;
        }
        if (null !== $search && '' !== trim($search)) {
            $conditions[] = 'position(lower(btrim(:search)) in normalized_label) > 0';
            $parameters['search'] = $search;
        }
        if ($parentEligible) {
            $conditions[] = 'depth < :max_depth';
            $parameters['max_depth'] = Category::MAX_TREE_DEPTH;
        }

        return [implode(' AND ', $conditions), $parameters];
    }
}
