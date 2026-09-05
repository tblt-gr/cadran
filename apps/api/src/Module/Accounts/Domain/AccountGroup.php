<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A workspace-owned exclusive bucket in the account tree.
 *
 * An account may belong to one group as its primary membership. Additional
 * tags may point at other groups, but only this exclusive link rolls into a
 * group's net-worth weight. The tree itself carries no amounts.
 */
final readonly class AccountGroup
{
    public const int MAX_LABEL_LENGTH = 80;
    public const int MAX_TREE_DEPTH = 8;
    public const int MAX_SORT_ORDER = 32767;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $label,
        public ?string $parentId,
        public int $sortOrder,
        public int $depth,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $archivedAt = null,
    ) {
        self::assertIdentifier($id);
        if (null !== $parentId) {
            self::assertIdentifier($parentId);
            if ($parentId === $id) {
                throw new InvalidAccountGroup('A group cannot be its own parent.');
            }
        }

        self::assertLabel($label);

        if ($depth < 1 || $depth > self::MAX_TREE_DEPTH) {
            throw new InvalidAccountGroup(sprintf('A group depth must be between 1 and %d.', self::MAX_TREE_DEPTH));
        }

        if ($sortOrder < 0 || $sortOrder > self::MAX_SORT_ORDER) {
            throw new InvalidAccountGroup(sprintf('A group order must be between 0 and %d.', self::MAX_SORT_ORDER));
        }

        if ($version < 1) {
            throw new InvalidAccountGroup('A group version must be positive.');
        }
    }

    public function reconfigure(
        string $label,
        ?string $parentId,
        int $sortOrder,
        int $depth,
        \DateTimeImmutable $updatedAt,
    ): self {
        if (null !== $this->archivedAt) {
            throw new InvalidAccountGroup('An archived group is read-only.');
        }

        return new self(
            id: $this->id,
            workspace: $this->workspace,
            label: trim($label),
            parentId: $parentId,
            sortOrder: $sortOrder,
            depth: $depth,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
            archivedAt: $this->archivedAt,
        );
    }

    public function archive(\DateTimeImmutable $archivedAt): self
    {
        if (null !== $this->archivedAt) {
            throw new InvalidAccountGroup('An archived group is read-only.');
        }

        return new self(
            id: $this->id,
            workspace: $this->workspace,
            label: $this->label,
            parentId: $this->parentId,
            sortOrder: $this->sortOrder,
            depth: $this->depth,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $archivedAt,
            archivedAt: $archivedAt,
        );
    }

    /**
     * Depth follows the exclusive parent. A reparent must rewrite every
     * descendant, including archived ones that stay attached and would
     * otherwise fail the database depth trigger on a later write.
     */
    public function rebaseDepth(int $depth, \DateTimeImmutable $updatedAt): self
    {
        return new self(
            id: $this->id,
            workspace: $this->workspace,
            label: $this->label,
            parentId: $this->parentId,
            sortOrder: $this->sortOrder,
            depth: $depth,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
            archivedAt: $this->archivedAt,
        );
    }

    public static function wouldCycle(string $groupId, ?string $newParentId, callable $parentOf): bool
    {
        $cursor = $newParentId;
        $guard = 0;
        while (null !== $cursor) {
            if ($cursor === $groupId) {
                return true;
            }

            ++$guard;
            if ($guard > self::MAX_TREE_DEPTH) {
                return true;
            }

            $cursor = $parentOf($cursor);
        }

        return false;
    }

    private static function assertIdentifier(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidAccountGroup('A group identifier must be a canonical UUID.');
        }
    }

    private static function assertLabel(string $label): void
    {
        if ($label !== trim($label) || '' === $label || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new InvalidAccountGroup(sprintf('A group label must contain between 1 and %d characters.', self::MAX_LABEL_LENGTH));
        }

        if (1 === preg_match('/[\p{Cc}\p{Cf}]/u', $label)) {
            throw new InvalidAccountGroup('A group label cannot contain control characters.');
        }
    }
}
