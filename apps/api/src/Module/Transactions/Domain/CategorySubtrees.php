<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Categories\Domain\Category;

/**
 * What each category covers once its subcategories are included, read from the whole
 * category forest of a workspace. Built once per request so that rolling a count up,
 * and expanding the categories of a filter, never walk the tree again.
 */
final readonly class CategorySubtrees
{
    /** @param array<string, list<string>> $subtrees category identifier => itself, then every descendant */
    private function __construct(private array $subtrees)
    {
    }

    /** @param iterable<Category> $categories every category of the workspace, archived ones included */
    public static function of(iterable $categories): self
    {
        $ids = [];
        $children = [];
        foreach ($categories as $category) {
            $ids[] = $category->id;
            if (null !== $category->parentId) {
                $children[$category->parentId][] = $category->id;
            }
        }

        $subtrees = [];
        foreach ($ids as $id) {
            $subtrees[$id] = self::descend($id, $children);
        }

        return new self($subtrees);
    }

    /**
     * The given categories and all their descendants, each once, in request order. An
     * identifier of no known category stays as it is: it matches nothing, like in the list.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function expand(array $ids): array
    {
        $expanded = [];
        foreach ($ids as $id) {
            array_push($expanded, ...($this->subtrees[$id] ?? [$id]));
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Every (ancestor, descendant) pair, an ancestor being paired with itself and with each of
     * its descendants: the two lists run side by side, so that they can be unnested together.
     *
     * @return array{list<string>, list<string>} the ancestors, then the descendants
     */
    public function pairs(): array
    {
        $ancestors = [];
        $descendants = [];
        foreach ($this->subtrees as $ancestor => $members) {
            foreach ($members as $member) {
                $ancestors[] = (string) $ancestor;
                $descendants[] = $member;
            }
        }

        return [$ancestors, $descendants];
    }

    /**
     * @param array<string, list<string>> $children
     *
     * @return list<string>
     */
    private static function descend(string $root, array $children): array
    {
        $members = [$root];
        $seen = [$root => true];
        // The list grows while it is read: a breadth-first walk. The seen set also stops a
        // corrupted parent cycle from looping.
        for ($index = 0; $index < count($members); ++$index) {
            foreach ($children[$members[$index]] ?? [] as $child) {
                if (!isset($seen[$child])) {
                    $seen[$child] = true;
                    $members[] = $child;
                }
            }
        }

        return $members;
    }
}
