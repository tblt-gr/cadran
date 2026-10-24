<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

/**
 * Derives the color a category is drawn with: its own explicit color, else the
 * nearest ancestor's. The result is computed at read time and never stored, so
 * a move, a cleared color or a changed ancestor needs no rewrite of descendants.
 *
 * The caller passes only categories of one workspace, which is what keeps an
 * ancestor of another workspace from ever being used.
 */
final class CategoryColorResolver
{
    /**
     * @param array<string, array{parentId: ?string, color: ?string}> $nodes
     *
     * @return array<string, ?string> the effective color of every node
     */
    public static function resolve(array $nodes): array
    {
        $effective = [];
        foreach ($nodes as $id => $_) {
            $effective[$id] = self::colorOf($id, $nodes);
        }

        return $effective;
    }

    /** @param array<string, array{parentId: ?string, color: ?string}> $nodes */
    private static function colorOf(string $id, array $nodes): ?string
    {
        $currentId = $id;
        // A category sits at depth 1 to MAX_TREE_DEPTH, so more steps than that can only be a cycle.
        for ($step = 0; $step < Category::MAX_TREE_DEPTH; ++$step) {
            $node = $nodes[$currentId] ?? null;
            if (null === $node) {
                return null;
            }
            if (null !== $node['color'] && 1 === preg_match('/^#[0-9A-F]{6}$/D', $node['color'])) {
                return $node['color'];
            }
            if (null === $node['parentId']) {
                return null;
            }
            $currentId = $node['parentId'];
        }

        return null;
    }
}
