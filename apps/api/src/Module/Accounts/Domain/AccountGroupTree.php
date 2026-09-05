<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Walks the exclusive tree so a share calculator can roll a child into each
 * ancestor once, without consulting tags.
 */
final class AccountGroupTree
{
    /**
     * @param list<AccountGroup> $groups
     *
     * @return list<GroupLineage>
     */
    public static function lineages(array $groups): array
    {
        $parentOf = [];
        foreach ($groups as $group) {
            $parentOf[$group->id] = $group->parentId;
        }

        $lineages = [];
        foreach ($groups as $group) {
            $ancestors = [$group->id];
            $cursor = $group->parentId;
            $guard = 0;
            while (null !== $cursor) {
                $ancestors[] = $cursor;
                $cursor = $parentOf[$cursor] ?? null;
                ++$guard;
                if ($guard > AccountGroup::MAX_TREE_DEPTH) {
                    break;
                }
            }

            $lineages[] = new GroupLineage($group->id, $ancestors);
        }

        return $lineages;
    }

    /**
     * @param list<AccountGroup> $descendants
     *
     * @return array<string, int>
     */
    public static function rebasedDepths(string $movedId, int $newDepth, array $descendants): array
    {
        if ($newDepth < 1 || $newDepth > AccountGroup::MAX_TREE_DEPTH) {
            throw new InvalidAccountGroup(sprintf('A group depth must be between 1 and %d.', AccountGroup::MAX_TREE_DEPTH));
        }

        $childrenOf = [];
        foreach ($descendants as $group) {
            if (null === $group->parentId) {
                continue;
            }

            $childrenOf[$group->parentId][] = $group;
        }

        $depths = [];
        /** @var list<array{0: string, 1: int}> $queue */
        $queue = [[$movedId, $newDepth]];
        for ($index = 0; $index < count($queue); ++$index) {
            [$id, $depth] = $queue[$index];
            foreach ($childrenOf[$id] ?? [] as $child) {
                $childDepth = $depth + 1;
                if ($childDepth > AccountGroup::MAX_TREE_DEPTH) {
                    throw new InvalidAccountGroup(sprintf('A group depth must be between 1 and %d.', AccountGroup::MAX_TREE_DEPTH));
                }

                $depths[$child->id] = $childDepth;
                $queue[] = [$child->id, $childDepth];
            }
        }

        return $depths;
    }
}
