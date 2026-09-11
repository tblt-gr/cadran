<?php

declare(strict_types=1);

namespace App\Module\Categories\Infrastructure\Persistence;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\WorkspaceScope;

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
        if ($workspace->id !== self::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A category row escaped its requested workspace.');
        }

        $axes = json_decode(self::text($row['default_analytic_axes'] ?? null), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($axes)) {
            throw new \UnexpectedValueException('Expected category axes to be an array.');
        }

        return new Category(
            id: self::text($row['id'] ?? null),
            workspace: $workspace,
            type: CategoryType::from(self::text($row['type'] ?? null)),
            label: self::text($row['label'] ?? null),
            parentId: self::nullableText($row['parent_id'] ?? null),
            icon: self::nullableText($row['icon'] ?? null),
            color: self::nullableText($row['color'] ?? null),
            defaultAnalyticAxes: array_map(
                static fn (mixed $axis): AnalyticAxis => AnalyticAxis::from(self::text($axis)),
                array_values($axes),
            ),
            budgetIncluded: self::boolean($row['budget_included'] ?? null),
            sortOrder: (int) self::text($row['sort_order'] ?? null),
            depth: (int) self::text($row['depth'] ?? null),
            version: (int) self::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(self::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(self::text($row['updated_at'] ?? null)),
            usedAt: self::date($row['used_at'] ?? null),
            archivedAt: self::date($row['archived_at'] ?? null),
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

    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    public static function nullableText(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
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
        return null === $value ? null : new \DateTimeImmutable(self::text($value));
    }
}
