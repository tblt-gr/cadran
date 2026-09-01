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
