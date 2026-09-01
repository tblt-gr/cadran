<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class Category
{
    public const int MAX_LABEL_LENGTH = 80;
    public const int MAX_TREE_DEPTH = 8;
    public const int MAX_SORT_ORDER = 32767;

    /**
     * @param list<AnalyticAxis> $defaultAnalyticAxes
     */
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public CategoryType $type,
        public string $label,
        public ?string $parentId,
        public ?string $icon,
        public ?string $color,
        public array $defaultAnalyticAxes,
        public bool $budgetIncluded,
        public int $sortOrder,
        public int $depth,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $usedAt = null,
        public ?\DateTimeImmutable $archivedAt = null,
    ) {
        self::assertIdentifier($id);
        if (null !== $parentId) {
            self::assertIdentifier($parentId);
            if ($parentId === $id) {
                throw new InvalidCategory('A category cannot be its own parent.');
            }
        }

        self::assertLabel($label);
        self::assertIcon($icon);
        self::assertColor($color);
        self::assertAxes($defaultAnalyticAxes);

        if ($depth < 1 || $depth > self::MAX_TREE_DEPTH) {
            throw new InvalidCategory(sprintf('A category depth must be between 1 and %d.', self::MAX_TREE_DEPTH));
        }

        if ($sortOrder < 0 || $sortOrder > self::MAX_SORT_ORDER) {
            throw new InvalidCategory(sprintf('A category order must be between 0 and %d.', self::MAX_SORT_ORDER));
        }

        if ($version < 1) {
            throw new InvalidCategory('A category version must be positive.');
        }
    }

    /**
     * @param list<AnalyticAxis> $defaultAnalyticAxes
     */
    public function reconfigure(
        CategoryType $type,
        string $label,
        ?string $icon,
        ?string $color,
        array $defaultAnalyticAxes,
        bool $budgetIncluded,
        int $sortOrder,
        \DateTimeImmutable $updatedAt,
    ): self {
        if (null !== $this->archivedAt) {
            throw new InvalidCategory('An archived category is read-only.');
        }

        if (null !== $this->usedAt && $type !== $this->type) {
            throw new InvalidCategory('A used category cannot change type.');
        }

        return new self(
            id: $this->id,
            workspace: $this->workspace,
            type: $type,
            label: trim($label),
            parentId: $this->parentId,
            icon: $icon,
            color: null === $color ? null : strtoupper($color),
            defaultAnalyticAxes: $defaultAnalyticAxes,
            budgetIncluded: $budgetIncluded,
            sortOrder: $sortOrder,
            depth: $this->depth,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
            usedAt: $this->usedAt,
            archivedAt: $this->archivedAt,
        );
    }

    private static function assertIdentifier(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidCategory('A category identifier must be a canonical UUID.');
        }
    }

    private static function assertLabel(string $label): void
    {
        if ($label !== trim($label) || '' === $label || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new InvalidCategory(sprintf('A category label must contain between 1 and %d characters.', self::MAX_LABEL_LENGTH));
        }

        if (1 === preg_match('/[\p{Cc}\p{Cf}]/u', $label)) {
            throw new InvalidCategory('A category label cannot contain control characters.');
        }
    }

    private static function assertIcon(?string $icon): void
    {
        if (null !== $icon && 1 !== preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $icon)) {
            throw new InvalidCategory('A category icon must be a supported icon key.');
        }
    }

    private static function assertColor(?string $color): void
    {
        if (null !== $color && 1 !== preg_match('/^#[0-9A-F]{6}$/D', $color)) {
            throw new InvalidCategory('A category color must be an uppercase hexadecimal RGB value.');
        }
    }

    /**
     * @param list<AnalyticAxis> $axes
     */
    private static function assertAxes(array $axes): void
    {
        $values = [];
        foreach ($axes as $axis) {
            $values[] = $axis->value;
        }

        if (count($values) !== count(array_unique($values))) {
            throw new InvalidCategory('A category axis cannot be selected twice.');
        }
    }
}
