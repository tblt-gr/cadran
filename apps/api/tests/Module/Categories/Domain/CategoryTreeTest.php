<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\Domain;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryTree;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\TestCase;

final class CategoryTreeTest extends TestCase
{
    private const string ROOT = '00000000-0000-7000-8000-0000000000c1';
    private const string CHILD = '00000000-0000-7000-8000-0000000000c2';
    private const string GRANDCHILD = '00000000-0000-7000-8000-0000000000c3';

    public function testEveryDescendantFollowsTheDepthOfTheMovedBranch(): void
    {
        $depths = CategoryTree::projectedDepths(self::ROOT, 3, $this->branch());

        self::assertSame([self::CHILD => 4, self::GRANDCHILD => 5], $depths);
    }

    public function testTheWalkReportsAnOverflowInsteadOfRefusingIt(): void
    {
        self::assertSame(9, CategoryTree::resultingDepth(self::ROOT, 7, $this->branch()));
    }

    public function testALeafKeepsItsOwnDepthAsTheResultingDepth(): void
    {
        self::assertSame(4, CategoryTree::resultingDepth(self::ROOT, 4, []));
        self::assertSame([], CategoryTree::projectedDepths(self::ROOT, 4, []));
    }

    public function testACategoryOutsideTheBranchIsNotRebased(): void
    {
        $unrelated = $this->category('00000000-0000-7000-8000-0000000000c9', '00000000-0000-7000-8000-0000000000d1', 4);

        $depths = CategoryTree::projectedDepths(self::ROOT, 1, [...$this->branch(), $unrelated]);

        self::assertArrayNotHasKey('00000000-0000-7000-8000-0000000000c9', $depths);
    }

    /** @return list<Category> */
    private function branch(): array
    {
        return [
            $this->category(self::CHILD, self::ROOT, 2),
            $this->category(self::GRANDCHILD, self::CHILD, 3),
        ];
    }

    private function category(string $id, ?string $parentId, int $depth): Category
    {
        $now = new \DateTimeImmutable('2026-09-01T12:00:00+00:00');

        return new Category(
            id: $id,
            workspace: WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            type: CategoryType::EXPENSE,
            label: 'Category '.substr($id, -2),
            parentId: $parentId,
            icon: null,
            color: null,
            defaultAnalyticAxes: [AnalyticAxis::ESSENTIAL],
            budgetIncluded: true,
            sortOrder: 0,
            depth: $depth,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
