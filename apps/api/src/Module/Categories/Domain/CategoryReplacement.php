<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A recorded redirection from one category to another.
 *
 * A merge redirects the whole history and carries no date; a replacement
 * redirects from a date onwards and leaves the source usable before it.
 *
 * Only {@see repointTo()} changes a recorded redirection, and only because the
 * category it named is being folded into another one in the same transaction:
 * the redirection keeps its meaning and follows its target rather than being
 * left naming a category nobody can select. Undoing a redirection remains a
 * new explicit operation.
 */
final readonly class CategoryReplacement
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $sourceCategoryId,
        public string $targetCategoryId,
        public CategoryReplacementKind $kind,
        public ?\DateTimeImmutable $effectiveFrom,
        public \DateTimeImmutable $createdAt,
    ) {
        self::assertIdentifier($id);
        self::assertIdentifier($sourceCategoryId);
        self::assertIdentifier($targetCategoryId);

        if ($sourceCategoryId === $targetCategoryId) {
            throw new InvalidCategoryReplacement('A category cannot replace itself.');
        }

        if (CategoryReplacementKind::MERGE === $kind && null !== $effectiveFrom) {
            throw new InvalidCategoryReplacement('A merge redirects the whole history and carries no date.');
        }

        if (CategoryReplacementKind::REPLACEMENT === $kind && null === $effectiveFrom) {
            throw new InvalidCategoryReplacement('A replacement must carry the date it takes effect on.');
        }
    }

    public static function merge(
        string $id,
        WorkspaceScope $workspace,
        string $sourceCategoryId,
        string $targetCategoryId,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $workspace, $sourceCategoryId, $targetCategoryId, CategoryReplacementKind::MERGE, null, $createdAt);
    }

    public static function from(
        string $id,
        WorkspaceScope $workspace,
        string $sourceCategoryId,
        string $targetCategoryId,
        \DateTimeImmutable $effectiveFrom,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $workspace,
            $sourceCategoryId,
            $targetCategoryId,
            CategoryReplacementKind::REPLACEMENT,
            $effectiveFrom->setTime(0, 0),
            $createdAt,
        );
    }

    public function repointTo(string $targetCategoryId): self
    {
        return new self(
            $this->id,
            $this->workspace,
            $this->sourceCategoryId,
            $targetCategoryId,
            $this->kind,
            $this->effectiveFrom,
            $this->createdAt,
        );
    }

    private static function assertIdentifier(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidCategoryReplacement('A category replacement identifier must be a canonical UUID.');
        }
    }
}
