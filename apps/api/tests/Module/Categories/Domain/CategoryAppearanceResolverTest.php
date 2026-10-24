<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\Domain;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryAppearanceResolver;
use PHPUnit\Framework\TestCase;

final class CategoryAppearanceResolverTest extends TestCase
{
    public function testTheIconInheritsIndependentlyFromTheColor(): void
    {
        $effective = CategoryAppearanceResolver::resolve([
            'a' => ['parentId' => null, 'color' => '#AAAAAA', 'icon' => 'home'],
            'b' => ['parentId' => 'a', 'color' => null, 'icon' => null],
            'c' => ['parentId' => 'b', 'color' => '#CCCCCC', 'icon' => null],
            'd' => ['parentId' => 'c', 'color' => null, 'icon' => 'zap'],
            'e' => ['parentId' => 'd', 'color' => null, 'icon' => null],
        ]);

        self::assertSame(['color' => '#AAAAAA', 'icon' => 'home'], $effective['b']);
        self::assertSame(['color' => '#CCCCCC', 'icon' => 'home'], $effective['c']);
        self::assertSame(['color' => '#CCCCCC', 'icon' => 'zap'], $effective['e']);
    }

    public function testAnInvalidIconIsIgnoredAndTheWalkContinues(): void
    {
        $effective = CategoryAppearanceResolver::resolve([
            'a' => ['parentId' => null, 'color' => null, 'icon' => 'home'],
            'b' => ['parentId' => 'a', 'color' => null, 'icon' => '<script>'],
        ]);

        self::assertSame('home', $effective['b']['icon']);
    }

    public function testAnIconChainWithoutAnyIconMissingParentOrCycleIsNull(): void
    {
        $effective = CategoryAppearanceResolver::resolve([
            'a' => ['parentId' => 'b', 'color' => null, 'icon' => null],
            'b' => ['parentId' => 'a', 'color' => null, 'icon' => null],
            'o' => ['parentId' => 'gone', 'color' => null, 'icon' => null],
        ]);

        self::assertNull($effective['a']['icon']);
        self::assertNull($effective['o']['icon']);
    }

    public function testAnExplicitColorWinsOverEveryAncestor(): void
    {
        $effective = CategoryAppearanceResolver::resolve([
            'root' => ['parentId' => null, 'color' => '#111111'],
            'child' => ['parentId' => 'root', 'color' => '#222222'],
        ]);

        self::assertSame('#111111', $effective['root']['color']);
        self::assertSame('#222222', $effective['child']['color']);
    }

    public function testADescendantInheritsTheNearestExplicitAncestorAcrossSeveralLevels(): void
    {
        $effective = CategoryAppearanceResolver::resolve([
            'a' => ['parentId' => null, 'color' => '#AAAAAA'],
            'b' => ['parentId' => 'a', 'color' => null],
            'c' => ['parentId' => 'b', 'color' => '#CCCCCC'],
            'd' => ['parentId' => 'c', 'color' => null],
            'e' => ['parentId' => 'd', 'color' => null],
            'f' => ['parentId' => 'b', 'color' => null],
        ]);

        self::assertSame('#AAAAAA', $effective['b']['color']);
        self::assertSame('#CCCCCC', $effective['d']['color']);
        self::assertSame('#CCCCCC', $effective['e']['color']);
        self::assertSame('#AAAAAA', $effective['f']['color']);
    }

    public function testAChainWithoutAnyColorIsNull(): void
    {
        $effective = CategoryAppearanceResolver::resolve([
            'a' => ['parentId' => null, 'color' => null],
            'b' => ['parentId' => 'a', 'color' => null],
        ]);

        self::assertNull($effective['a']['color']);
        self::assertNull($effective['b']['color']);
    }

    public function testAMissingParentStopsTheWalkWithNull(): void
    {
        $effective = CategoryAppearanceResolver::resolve([
            'orphan' => ['parentId' => 'elsewhere', 'color' => null],
        ]);

        self::assertNull($effective['orphan']['color']);
    }

    public function testACycleTerminatesWithNull(): void
    {
        $effective = CategoryAppearanceResolver::resolve([
            'a' => ['parentId' => 'b', 'color' => null],
            'b' => ['parentId' => 'a', 'color' => null],
        ]);

        self::assertNull($effective['a']['color']);
        self::assertNull($effective['b']['color']);
    }

    public function testAnInvalidStoredColorIsIgnoredAndTheWalkContinues(): void
    {
        $effective = CategoryAppearanceResolver::resolve([
            'a' => ['parentId' => null, 'color' => '#ABCDEF'],
            'b' => ['parentId' => 'a', 'color' => 'url(http://evil)'],
            'c' => ['parentId' => 'a', 'color' => '#abcdef'],
        ]);

        self::assertSame('#ABCDEF', $effective['b']['color']);
        self::assertSame('#ABCDEF', $effective['c']['color']);
    }

    public function testTheWalkIsBoundedByTheHierarchyDepth(): void
    {
        $nodes = ['n0' => ['parentId' => null, 'color' => '#010203']];
        for ($level = 1; $level <= Category::MAX_TREE_DEPTH + 2; ++$level) {
            $nodes['n'.$level] = ['parentId' => 'n'.($level - 1), 'color' => null];
        }

        $effective = CategoryAppearanceResolver::resolve($nodes);

        // n0 is depth 1, so n7 is the deepest legal node and n8 is beyond the hierarchy limit.
        self::assertSame('#010203', $effective['n'.(Category::MAX_TREE_DEPTH - 1)]['color']);
        self::assertNull($effective['n'.Category::MAX_TREE_DEPTH]['color']);
    }
}
