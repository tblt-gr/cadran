<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A workspace-owned exclusive bucket in the account tree.
 *
 * An account belongs to exactly one group, possibly a sub-group, and also
 * counts in every ancestor of that group through the exclusive roll-up. The tree itself carries no amounts.
 */
final readonly class AccountGroup
{
    public ?string $color;
    public AccountGroupColorMode $colorMode;

    public const int MAX_LABEL_LENGTH = 80;
    public const int MAX_TREE_DEPTH = 8;
    public const int MAX_SORT_ORDER = 32767;
    public const int MAX_COLOR_SLOT = 32767;

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
        ?string $color = null,
        ?AccountGroupColorMode $colorMode = null,
        public int $colorSlot = 0,
    ) {
        $this->color = AccountGroupColor::normalize($color);
        // A mode left out keeps the pre-mode meaning of a stored color.
        $this->colorMode = $colorMode ?? (null === $this->color ? AccountGroupColorMode::AUTO : AccountGroupColorMode::CUSTOM);
        if (AccountGroupColorMode::CUSTOM === $this->colorMode && null === $this->color) {
            throw new InvalidAccountGroup('A custom group color requires a color.');
        }
        if (AccountGroupColorMode::CUSTOM !== $this->colorMode && null !== $this->color) {
            throw new InvalidAccountGroup('Only a custom group color carries an explicit color.');
        }
        if (AccountGroupColorMode::PARENT === $this->colorMode && null === $parentId) {
            throw new InvalidAccountGroup('A root group cannot inherit its parent color.');
        }
        if ($colorSlot < 0 || $colorSlot > self::MAX_COLOR_SLOT) {
            throw new InvalidAccountGroup(sprintf('A group color slot must be between 0 and %d.', self::MAX_COLOR_SLOT));
        }
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
        ?string $color = null,
        ?AccountGroupColorMode $colorMode = null,
        ?int $colorSlot = null,
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
            color: $color,
            colorMode: $colorMode,
            colorSlot: $colorSlot ?? $this->colorSlot,
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
            color: $this->color,
            colorMode: $this->colorMode,
            colorSlot: $this->colorSlot,
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
            color: $this->color,
            colorMode: $this->colorMode,
            colorSlot: $this->colorSlot,
        );
    }

    /**
     * Smallest slot no current sibling holds, archived siblings included, so an
     * effective color never moves while its sibling exists.
     *
     * @param list<int> $usedSlots
     */
    public static function nextColorSlot(array $usedSlots): int
    {
        $taken = array_flip($usedSlots);
        $slot = 0;
        while (isset($taken[$slot])) {
            ++$slot;
        }

        return $slot;
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
