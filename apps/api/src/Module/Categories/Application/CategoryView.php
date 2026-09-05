<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\Category;

final readonly class CategoryView
{
    /** @param list<string> $defaultAnalyticAxes */
    public function __construct(
        public string $id,
        public string $type,
        public string $label,
        public ?string $parentId,
        public ?string $parentLabel,
        public ?string $icon,
        public ?string $color,
        public array $defaultAnalyticAxes,
        public bool $budgetIncluded,
        public int $sortOrder,
        public int $depth,
        public int $version,
        public bool $used,
        public bool $typeEditable,
        public ?string $typeEditReason,
        public bool $canAcceptChildren,
        public ?string $archivedAt,
        public ?CategoryReplacementView $replacement,
    ) {
    }

    public static function fromCategory(
        Category $category,
        bool $hasChildren,
        ?string $parentLabel,
        ?CategoryReplacementView $replacement = null,
    ): self {
        [$typeEditable, $typeEditReason] = self::typeEligibility($category, $hasChildren);

        return new self(
            id: $category->id,
            type: $category->type->value,
            label: $category->label,
            parentId: $category->parentId,
            parentLabel: $parentLabel,
            icon: $category->icon,
            color: $category->color,
            defaultAnalyticAxes: array_map(static fn ($axis): string => $axis->value, $category->defaultAnalyticAxes),
            budgetIncluded: $category->budgetIncluded,
            sortOrder: $category->sortOrder,
            depth: $category->depth,
            version: $category->version,
            used: null !== $category->usedAt,
            typeEditable: $typeEditable,
            typeEditReason: $typeEditReason,
            canAcceptChildren: null === $category->archivedAt && $category->depth < Category::MAX_TREE_DEPTH,
            archivedAt: $category->archivedAt?->format(DATE_ATOM),
            replacement: $replacement,
        );
    }

    /** @return array{bool, ?string} */
    private static function typeEligibility(Category $category, bool $hasChildren): array
    {
        if (null !== $category->archivedAt) {
            return [false, 'ARCHIVED'];
        }
        if (null !== $category->usedAt) {
            return [false, 'USED'];
        }
        if (null !== $category->parentId) {
            return [false, 'CHILD'];
        }
        if ($hasChildren) {
            return [false, 'HAS_CHILDREN'];
        }

        return [true, null];
    }
}
