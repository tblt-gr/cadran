<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

/**
 * Walks a category branch so a move or a merge can rewrite the depth of every
 * descendant in one pass.
 *
 * The walk never refuses an operation: an impact preview has to report a branch
 * that would grow too deep rather than fail on it. The ceiling is enforced
 * elsewhere — by the DEPTH_EXCEEDED blocker of the impact assessment, by the
 * {@see Category} constructor on every rewritten descendant, and by the database
 * depth trigger.
 */
final class CategoryTree
{
    /**
     * @param list<Category> $descendants every category below $rootId, in any order
     *
     * @return array<string, int> the depth each descendant would take
     */
    public static function projectedDepths(string $rootId, int $rootDepth, array $descendants): array
    {
        $childrenOf = [];
        foreach ($descendants as $descendant) {
            if (null === $descendant->parentId) {
                continue;
            }

            $childrenOf[$descendant->parentId][] = $descendant;
        }

        $depths = [];
        /** @var list<array{0: string, 1: int}> $queue */
        $queue = [[$rootId, $rootDepth]];
        for ($index = 0; $index < count($queue); ++$index) {
            [$id, $depth] = $queue[$index];
            foreach ($childrenOf[$id] ?? [] as $child) {
                $depths[$child->id] = $depth + 1;
                $queue[] = [$child->id, $depth + 1];
            }
        }

        return $depths;
    }

    /**
     * The tallest depth the branch reaches once rebased, bound or not.
     *
     * @param list<Category> $descendants
     */
    public static function resultingDepth(string $rootId, int $rootDepth, array $descendants): int
    {
        $depths = self::projectedDepths($rootId, $rootDepth, $descendants);

        return [] === $depths ? $rootDepth : max($rootDepth, max($depths));
    }
}
