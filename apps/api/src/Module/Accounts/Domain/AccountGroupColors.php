<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Effective colors of a whole workspace tree. Inherited colors are computed
 * on read and never copied into child records, so a parent change reaches
 * every inheriting descendant at once.
 */
final readonly class AccountGroupColors
{
    /** @param array<string, string> $effective */
    private function __construct(private array $effective)
    {
    }

    /** @param list<AccountGroup> $groups every group of the workspace, archived included */
    public static function of(array $groups): self
    {
        return new self(self::resolveAll($groups));
    }

    /**
     * @param list<AccountGroup> $groups
     *
     * @return array<string, string>
     */
    public static function resolveAll(array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            $byId[$group->id] = $group;
        }

        $resolved = [];
        foreach ($groups as $group) {
            self::resolve($group, $byId, $resolved);
        }

        return $resolved;
    }

    public function effective(AccountGroup $group): string
    {
        return $this->effective[$group->id] ?? AccountGroupColor::defaultFor($group->id);
    }

    /** What the default mode of the group would produce now. */
    public function defaultFor(AccountGroup $group): string
    {
        $parent = $this->parent($group);

        return null === $parent
            ? AccountGroupColor::defaultFor($group->id)
            : AccountGroupColor::shade($parent, $group->colorSlot);
    }

    public function parent(AccountGroup $group): ?string
    {
        return null === $group->parentId ? null : ($this->effective[$group->parentId] ?? null);
    }

    /**
     * Parents are resolved first by walking up, bounded by the tree depth so a
     * corrupted cycle cannot recurse forever: the group that closes the loop
     * reads as a root.
     *
     * @param array<string, AccountGroup> $byId
     * @param array<string, string>       $resolved
     */
    private static function resolve(AccountGroup $group, array $byId, array &$resolved, int $level = 0): string
    {
        if (isset($resolved[$group->id])) {
            return $resolved[$group->id];
        }

        $parent = null === $group->parentId ? null : ($byId[$group->parentId] ?? null);
        if (null !== $parent && $level >= AccountGroup::MAX_TREE_DEPTH) {
            $parent = null;
        }

        $color = match (true) {
            AccountGroupColorMode::CUSTOM === $group->colorMode && null !== $group->color => $group->color,
            null === $parent => AccountGroupColor::defaultFor($group->id),
            AccountGroupColorMode::PARENT === $group->colorMode => self::resolve($parent, $byId, $resolved, $level + 1),
            default => AccountGroupColor::shade(self::resolve($parent, $byId, $resolved, $level + 1), $group->colorSlot),
        };

        return $resolved[$group->id] = $color;
    }
}
