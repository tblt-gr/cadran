<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\Domain;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryColorResolver;
use PHPUnit\Framework\TestCase;

final class CategoryColorResolverTest extends TestCase
{
    public function testAnExplicitColorWinsOverEveryAncestor(): void
    {
        $effective = CategoryColorResolver::resolve([
            'root' => ['parentId' => null, 'color' => '#111111'],
            'child' => ['parentId' => 'root', 'color' => '#222222'],
        ]);

        self::assertSame('#111111', $effective['root']);
        self::assertSame('#222222', $effective['child']);
    }

    public function testADescendantInheritsTheNearestExplicitAncestorAcrossSeveralLevels(): void
    {
        $effective = CategoryColorResolver::resolve([
            'a' => ['parentId' => null, 'color' => '#AAAAAA'],
            'b' => ['parentId' => 'a', 'color' => null],
            'c' => ['parentId' => 'b', 'color' => '#CCCCCC'],
            'd' => ['parentId' => 'c', 'color' => null],
            'e' => ['parentId' => 'd', 'color' => null],
            'f' => ['parentId' => 'b', 'color' => null],
        ]);

        self::assertSame('#AAAAAA', $effective['b']);
        self::assertSame('#CCCCCC', $effective['d']);
        self::assertSame('#CCCCCC', $effective['e']);
        self::assertSame('#AAAAAA', $effective['f']);
    }

    public function testAChainWithoutAnyColorIsNull(): void
    {
        $effective = CategoryColorResolver::resolve([
            'a' => ['parentId' => null, 'color' => null],
            'b' => ['parentId' => 'a', 'color' => null],
        ]);

        self::assertNull($effective['a']);
        self::assertNull($effective['b']);
    }

    public function testAMissingParentStopsTheWalkWithNull(): void
    {
        $effective = CategoryColorResolver::resolve([
            'orphan' => ['parentId' => 'elsewhere', 'color' => null],
        ]);

        self::assertNull($effective['orphan']);
    }

    public function testACycleTerminatesWithNull(): void
    {
        $effective = CategoryColorResolver::resolve([
            'a' => ['parentId' => 'b', 'color' => null],
            'b' => ['parentId' => 'a', 'color' => null],
        ]);

        self::assertNull($effective['a']);
        self::assertNull($effective['b']);
    }

    public function testAnInvalidStoredColorIsIgnoredAndTheWalkContinues(): void
    {
        $effective = CategoryColorResolver::resolve([
            'a' => ['parentId' => null, 'color' => '#ABCDEF'],
            'b' => ['parentId' => 'a', 'color' => 'url(http://evil)'],
            'c' => ['parentId' => 'a', 'color' => '#abcdef'],
        ]);

        self::assertSame('#ABCDEF', $effective['b']);
        self::assertSame('#ABCDEF', $effective['c']);
    }

    public function testTheWalkIsBoundedByTheHierarchyDepth(): void
    {
        $nodes = ['n0' => ['parentId' => null, 'color' => '#010203']];
        for ($level = 1; $level <= Category::MAX_TREE_DEPTH + 2; ++$level) {
            $nodes['n'.$level] = ['parentId' => 'n'.($level - 1), 'color' => null];
        }

        $effective = CategoryColorResolver::resolve($nodes);

        // n0 is depth 1, so n7 is the deepest legal node and n8 is beyond the hierarchy limit.
        self::assertSame('#010203', $effective['n'.(Category::MAX_TREE_DEPTH - 1)]);
        self::assertNull($effective['n'.Category::MAX_TREE_DEPTH]);
    }
}
