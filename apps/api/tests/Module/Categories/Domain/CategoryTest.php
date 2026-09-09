<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\Domain;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Categories\Domain\InvalidCategory;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testItCarriesTypedHierarchicalAndAnalyticMetadata(): void
    {
        $category = $this->category();

        self::assertSame(CategoryType::EXPENSE, $category->type);
        self::assertSame('Restaurants', $category->label);
        self::assertSame([AnalyticAxis::DISCRETIONARY, AnalyticAxis::VARIABLE], $category->defaultAnalyticAxes);
        self::assertTrue($category->budgetIncluded);
    }

    #[DataProvider('invalidMetadata')]
    public function testItRejectsUnboundedOrExecutableMetadata(
        string $label,
        ?string $icon,
        ?string $color,
    ): void {
        $this->expectException(InvalidCategory::class);

        $this->category(label: $label, icon: $icon, color: $color);
    }

    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function invalidMetadata(): iterable
    {
        yield 'empty label' => ['', 'utensils', '#AABBCC'];
        yield 'untrimmed label' => [' Restaurants ', 'utensils', '#AABBCC'];
        yield 'control in label' => ["Restau\nSuite", 'utensils', '#AABBCC'];
        yield 'script-shaped icon' => ['Restaurants', '<script>', '#AABBCC'];
        yield 'non canonical color' => ['Restaurants', 'utensils', '#aabbcc'];
    }

    public function testAFirstUseMakesTypeHistoricalWithoutTouchingAnAlreadyUsedCategory(): void
    {
        $fresh = $this->category();
        $usedAt = new \DateTimeImmutable('2026-09-08T09:00:00+00:00');
        $used = $fresh->markUsed($usedAt);

        self::assertSame($usedAt, $used->usedAt);
        self::assertSame(2, $used->version);
        self::assertSame($used, $used->markUsed(new \DateTimeImmutable('2026-09-09T09:00:00+00:00')));
    }

    public function testAUsedCategoryCannotSwitchFinancialType(): void
    {
        $category = $this->category(usedAt: new \DateTimeImmutable());

        $this->expectException(InvalidCategory::class);
        $this->expectExceptionMessage('used category');

        $category->reconfigure(
            type: CategoryType::INCOME,
            label: $category->label,
            icon: $category->icon,
            color: $category->color,
            defaultAnalyticAxes: $category->defaultAnalyticAxes,
            budgetIncluded: $category->budgetIncluded,
            sortOrder: $category->sortOrder,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function testAnArchivedCategoryIsReadOnly(): void
    {
        $category = $this->category(archivedAt: new \DateTimeImmutable());

        $this->expectException(InvalidCategory::class);
        $this->expectExceptionMessage('archived category');

        $category->reconfigure(
            type: $category->type,
            label: 'Nouvelle étiquette',
            icon: $category->icon,
            color: $category->color,
            defaultAnalyticAxes: $category->defaultAnalyticAxes,
            budgetIncluded: $category->budgetIncluded,
            sortOrder: $category->sortOrder,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function testTheTreeDepthIsBounded(): void
    {
        $this->expectException(InvalidCategory::class);

        $this->category(depth: Category::MAX_TREE_DEPTH + 1);
    }

    public function testAMoveCarriesTheBranchToItsNewDepth(): void
    {
        $moved = $this->category()->moveTo(
            '00000000-0000-7000-8000-0000000000c2',
            2,
            new \DateTimeImmutable('2026-09-05T09:00:00+00:00'),
        );

        self::assertSame('00000000-0000-7000-8000-0000000000c2', $moved->parentId);
        self::assertSame(2, $moved->depth);
        self::assertSame(2, $moved->version);
    }

    public function testAnArchivedCategoryCannotBeMovedByHand(): void
    {
        $this->expectException(InvalidCategory::class);
        $this->expectExceptionMessage('read-only');

        $this->category(archivedAt: new \DateTimeImmutable())
            ->moveTo(null, 1, new \DateTimeImmutable());
    }

    public function testAnArchivedCategoryStillFollowsAnAncestorMove(): void
    {
        $archived = $this->category(archivedAt: new \DateTimeImmutable('2026-09-01T12:00:00+00:00'));

        $followed = $archived->followAncestorMove(
            '00000000-0000-7000-8000-0000000000c2',
            3,
            new \DateTimeImmutable('2026-09-05T09:00:00+00:00'),
        );

        self::assertSame(3, $followed->depth);
        self::assertNotNull($followed->archivedAt);
    }

    public function testArchivingIsIdempotentOnlyByRefusal(): void
    {
        $archived = $this->category()->archive(new \DateTimeImmutable('2026-09-05T09:00:00+00:00'));

        self::assertNotNull($archived->archivedAt);

        $this->expectException(InvalidCategory::class);
        $archived->archive(new \DateTimeImmutable('2026-09-06T09:00:00+00:00'));
    }

    public function testMovingUnderOwnDescendantIsACycle(): void
    {
        $parents = [
            '00000000-0000-7000-8000-0000000000c2' => '00000000-0000-7000-8000-0000000000c3',
            '00000000-0000-7000-8000-0000000000c3' => '00000000-0000-7000-8000-0000000000c1',
        ];
        $parentOf = static fn (string $id): ?string => $parents[$id] ?? null;

        self::assertTrue(Category::wouldCycle(
            '00000000-0000-7000-8000-0000000000c1',
            '00000000-0000-7000-8000-0000000000c2',
            $parentOf,
        ));
        self::assertFalse(Category::wouldCycle(
            '00000000-0000-7000-8000-0000000000c9',
            '00000000-0000-7000-8000-0000000000c2',
            $parentOf,
        ));
        self::assertFalse(Category::wouldCycle('00000000-0000-7000-8000-0000000000c1', null, $parentOf));
    }

    public function testAnAlreadyLoopingChainIsReportedRatherThanWalkedForever(): void
    {
        $parentOf = static fn (string $id): string => '00000000-0000-7000-8000-0000000000c2' === $id
            ? '00000000-0000-7000-8000-0000000000c3'
            : '00000000-0000-7000-8000-0000000000c2';

        self::assertTrue(Category::wouldCycle(
            '00000000-0000-7000-8000-0000000000c1',
            '00000000-0000-7000-8000-0000000000c2',
            $parentOf,
        ));
    }

    private function category(
        string $label = 'Restaurants',
        ?string $icon = 'utensils',
        ?string $color = '#AABBCC',
        int $depth = 1,
        ?\DateTimeImmutable $usedAt = null,
        ?\DateTimeImmutable $archivedAt = null,
    ): Category {
        $now = new \DateTimeImmutable('2026-09-01T12:00:00+00:00');

        return new Category(
            id: '00000000-0000-7000-8000-0000000000c1',
            workspace: WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            type: CategoryType::EXPENSE,
            label: $label,
            parentId: null,
            icon: $icon,
            color: $color,
            defaultAnalyticAxes: [AnalyticAxis::DISCRETIONARY, AnalyticAxis::VARIABLE],
            budgetIncluded: true,
            sortOrder: 10,
            depth: $depth,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            usedAt: $usedAt,
            archivedAt: $archivedAt,
        );
    }
}
