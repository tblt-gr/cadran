<?php

declare(strict_types=1);

namespace App\Module\Categories\Infrastructure\Persistence;

use App\Module\Categories\Domain\CategoryReplacement;
use App\Module\Categories\Domain\CategoryReplacementKind;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Rebuilds a redirection from the `category_replacements` row that stores it.
 * The workspace is compared rather than taken from the row, so a query that
 * leaked another workspace's record fails here instead of hydrating an object
 * that looks legitimate.
 */
final readonly class CategoryReplacementRow
{
    /** @param array<string, mixed> $row */
    public static function hydrate(array $row, WorkspaceScope $workspace): CategoryReplacement
    {
        if ($workspace->id !== CategoryRow::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A category replacement row escaped its requested workspace.');
        }

        $effectiveFrom = $row['effective_from'] ?? null;

        return new CategoryReplacement(
            id: CategoryRow::text($row['id'] ?? null),
            workspace: $workspace,
            sourceCategoryId: CategoryRow::text($row['source_category_id'] ?? null),
            targetCategoryId: CategoryRow::text($row['target_category_id'] ?? null),
            kind: CategoryReplacementKind::from(CategoryRow::text($row['kind'] ?? null)),
            effectiveFrom: null === $effectiveFrom
                ? null
                : new \DateTimeImmutable(CategoryRow::text($effectiveFrom)),
            createdAt: new \DateTimeImmutable(CategoryRow::text($row['created_at'] ?? null)),
        );
    }
}
