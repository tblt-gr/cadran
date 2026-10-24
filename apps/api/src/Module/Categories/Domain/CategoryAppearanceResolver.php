<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

/**
 * Derives the color and the icon a category is drawn with: its own explicit
 * value, else the nearest ancestor's. Each attribute inherits independently.
 * The result is computed at read time and never stored, so a move, a cleared
 * value or a changed ancestor needs no rewrite of descendants.
 *
 * The caller passes only categories of one workspace, which is what keeps an
 * ancestor of another workspace from ever being used.
 */
final class CategoryAppearanceResolver
{
    private const string COLOR_FORMAT = '/^#[0-9A-F]{6}$/D';
    private const string ICON_FORMAT = '/^[a-z][a-z0-9-]{0,31}$/D';

    /**
     * @param array<string, array{parentId: ?string, color: ?string, icon?: ?string}> $nodes
     *
     * @return array<string, array{color: ?string, icon: ?string}> the effective appearance of every node
     */
    public static function resolve(array $nodes): array
    {
        $effective = [];
        foreach ($nodes as $id => $_) {
            $effective[$id] = [
                'color' => self::inherited($id, $nodes, 'color', self::COLOR_FORMAT),
                'icon' => self::inherited($id, $nodes, 'icon', self::ICON_FORMAT),
            ];
        }

        return $effective;
    }

    /**
     * @param array<string, array{parentId: ?string, color: ?string, icon?: ?string}> $nodes
     * @param 'color'|'icon'                                                          $attribute
     */
    private static function inherited(string $id, array $nodes, string $attribute, string $format): ?string
    {
        $currentId = $id;
        // A category sits at depth 1 to MAX_TREE_DEPTH, so more steps than that can only be a cycle.
        for ($step = 0; $step < Category::MAX_TREE_DEPTH; ++$step) {
            $node = $nodes[$currentId] ?? null;
            if (null === $node) {
                return null;
            }
            $value = $node[$attribute] ?? null;
            if (null !== $value && 1 === preg_match($format, $value)) {
                return $value;
            }
            if (null === $node['parentId']) {
                return null;
            }
            $currentId = $node['parentId'];
        }

        return null;
    }
}
