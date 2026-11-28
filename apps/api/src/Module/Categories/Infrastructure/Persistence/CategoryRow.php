<?php

declare(strict_types=1);

namespace App\Module\Categories\Infrastructure\Persistence;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;

/**
 * Translates between a category and the `category_categories` row that stores
 * it, in both directions.
 *
 * The two mappings sit together on purpose: a column added to the write side
 * and forgotten on the read side is the failure this class exists to make
 * obvious. DBAL yields `mixed` for every column, so the narrowing helpers
 * assert the shape the domain expects rather than casting a surprise away.
 */
final readonly class CategoryRow
{
    /**
     * The workspace is compared, never taken from the row: a query that leaked
     * another workspace's record must fail loudly here instead of hydrating an
     * object that looks legitimate.
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row, WorkspaceScope $workspace): Category
    {
        if ($workspace->id !== DbalValue::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A category row escaped its requested workspace.');
        }

        $axes = json_decode(DbalValue::text($row['default_analytic_axes'] ?? null), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($axes)) {
            throw new \UnexpectedValueException('Expected category axes to be an array.');
        }

        return new Category(
            id: DbalValue::text($row['id'] ?? null),
            workspace: $workspace,
            type: CategoryType::from(DbalValue::text($row['type'] ?? null)),
            label: DbalValue::text($row['label'] ?? null),
            parentId: DbalValue::nullableText($row['parent_id'] ?? null),
            icon: DbalValue::nullableText($row['icon'] ?? null),
            color: DbalValue::nullableText($row['color'] ?? null),
            defaultAnalyticAxes: array_map(
                static fn (mixed $axis): AnalyticAxis => AnalyticAxis::from(DbalValue::text($axis)),
                array_values($axes),
            ),
            budgetIncluded: DbalValue::boolean($row['budget_included'] ?? null),
            sortOrder: (int) DbalValue::text($row['sort_order'] ?? null),
            depth: (int) DbalValue::text($row['depth'] ?? null),
            version: (int) DbalValue::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(DbalValue::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(DbalValue::text($row['updated_at'] ?? null)),
            usedAt: DbalValue::nullableInstant($row['used_at'] ?? null),
            archivedAt: DbalValue::nullableInstant($row['archived_at'] ?? null),
        );
    }

    /**
     * The mutable columns of a category. `id`, `workspace_id` and `created_at`
     * are absent because they are written once and never updated.
     *
     * @return array<string, mixed>
     */
    public static function columns(Category $category): array
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
}
