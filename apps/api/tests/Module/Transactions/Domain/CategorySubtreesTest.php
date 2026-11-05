<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Transactions\Domain\CategorySubtrees;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class CategorySubtreesTest extends TestCase
{
    private const string FOOD = '00000000-0000-7000-8000-00000000c001';
    private const string GROCERIES = '00000000-0000-7000-8000-00000000c002';
    private const string ORGANIC = '00000000-0000-7000-8000-00000000c003';
    private const string RESTAURANTS = '00000000-0000-7000-8000-00000000c004';
    private const string TRANSPORT = '00000000-0000-7000-8000-00000000c005';
    private const string UNKNOWN = '00000000-0000-7000-8000-00000000c0ff';

    public function testEachCategoryCoversItselfThenEveryDescendantAtAnyDepth(): void
    {
        $subtrees = CategorySubtrees::of($this->tree());

        self::assertSame([self::FOOD, self::GROCERIES, self::RESTAURANTS, self::ORGANIC], $subtrees->expand([self::FOOD]));
        self::assertSame([self::GROCERIES, self::ORGANIC], $subtrees->expand([self::GROCERIES]));
        self::assertSame([self::ORGANIC], $subtrees->expand([self::ORGANIC]));
        self::assertSame([self::TRANSPORT], $subtrees->expand([self::TRANSPORT]));
    }

    public function testPairsListEveryAncestorWithItselfAndEachOfItsDescendantsSideBySide(): void
    {
        [$ancestors, $descendants] = CategorySubtrees::of($this->tree())->pairs();

        self::assertSame(
            [
                self::FOOD.'>'.self::FOOD, self::FOOD.'>'.self::GROCERIES, self::FOOD.'>'.self::RESTAURANTS, self::FOOD.'>'.self::ORGANIC,
                self::GROCERIES.'>'.self::GROCERIES, self::GROCERIES.'>'.self::ORGANIC,
                self::RESTAURANTS.'>'.self::RESTAURANTS,
                self::ORGANIC.'>'.self::ORGANIC,
                self::TRANSPORT.'>'.self::TRANSPORT,
            ],
            array_map(static fn (string $ancestor, string $descendant): string => $ancestor.'>'.$descendant, $ancestors, $descendants),
        );
    }

    public function testExpandingSeveralCategoriesListsEveryIdOnceInRequestOrder(): void
    {
        $subtrees = CategorySubtrees::of($this->tree());

        self::assertSame(
            [self::GROCERIES, self::ORGANIC, self::TRANSPORT, self::FOOD, self::RESTAURANTS],
            $subtrees->expand([self::GROCERIES, self::TRANSPORT, self::FOOD, self::ORGANIC]),
        );
    }

    public function testAnIdentifierOfNoKnownCategoryExpandsToItselfSoItStillMatchesNothing(): void
    {
        self::assertSame([self::UNKNOWN, self::TRANSPORT], CategorySubtrees::of($this->tree())->expand([self::UNKNOWN, self::TRANSPORT]));
        self::assertSame([], CategorySubtrees::of($this->tree())->expand([]));
    }

    public function testAnEmptyForestHasNoSubtree(): void
    {
        self::assertSame([[], []], CategorySubtrees::of([])->pairs());
    }

    public function testACorruptedParentCycleTerminatesInsteadOfLooping(): void
    {
        $cycle = [
            $this->category(self::FOOD, self::GROCERIES),
            $this->category(self::GROCERIES, self::FOOD),
        ];

        $subtrees = CategorySubtrees::of($cycle);

        self::assertSame([self::FOOD, self::GROCERIES], $subtrees->expand([self::FOOD]));
        self::assertSame([self::GROCERIES, self::FOOD], $subtrees->expand([self::GROCERIES]));
    }

    /** @return list<Category> */
    private function tree(): array
    {
        return [
            $this->category(self::FOOD, null),
            $this->category(self::GROCERIES, self::FOOD),
            $this->category(self::RESTAURANTS, self::FOOD),
            $this->category(self::ORGANIC, self::GROCERIES),
            $this->category(self::TRANSPORT, null),
        ];
    }

    private function category(string $id, ?string $parentId): Category
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Category($id, WorkspaceFixture::own(), CategoryType::EXPENSE, 'Category '.substr($id, -4), $parentId, null, null, [], true, 0, null === $parentId ? 1 : 2, 1, $at, $at);
    }
}
