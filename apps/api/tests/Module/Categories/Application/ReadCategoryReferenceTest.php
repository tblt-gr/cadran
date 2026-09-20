<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\Application;

use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Categories\Application\Double\InMemoryCategoryRepository;
use PHPUnit\Framework\TestCase;

final class ReadCategoryReferenceTest extends TestCase
{
    private const string WORKSPACE = '11111111-1111-4111-8111-111111111111';
    private const string OTHER_WORKSPACE = '22222222-2222-4222-8222-222222222222';
    private const string PARENT_ID = '44444444-4444-4444-8444-444444444444';
    private const string CHILD_ID = '55555555-5555-4555-8555-555555555555';

    public function testItReturnsNullForAnUnknownCategory(): void
    {
        $read = new ReadCategoryReference(new InMemoryCategoryRepository());

        self::assertNull(($read)(WorkspaceScope::fromString(self::WORKSPACE), self::PARENT_ID));
    }

    public function testItReportsAnArchivedCategory(): void
    {
        $categories = new InMemoryCategoryRepository();
        $categories->add($this->category(self::PARENT_ID, null, archived: true));
        $read = new ReadCategoryReference($categories);

        $fact = ($read)(WorkspaceScope::fromString(self::WORKSPACE), self::PARENT_ID);

        self::assertNotNull($fact);
        self::assertSame('Category', $fact->label);
        self::assertTrue($fact->archived);
        self::assertSame([], $fact->ancestorIds);
    }

    public function testItDoesNotReturnAForeignCategoryLabel(): void
    {
        $categories = new InMemoryCategoryRepository();
        $categories->add($this->category(self::PARENT_ID, null, workspace: self::OTHER_WORKSPACE));
        $read = new ReadCategoryReference($categories);

        self::assertNull(($read)(WorkspaceScope::fromString(self::WORKSPACE), self::PARENT_ID));
    }

    public function testItWalksTheAncestorChainToTheRoot(): void
    {
        $categories = new InMemoryCategoryRepository();
        $categories->add($this->category(self::PARENT_ID, null));
        $categories->add($this->category(self::CHILD_ID, self::PARENT_ID));
        $read = new ReadCategoryReference($categories);

        $fact = ($read)(WorkspaceScope::fromString(self::WORKSPACE), self::CHILD_ID);

        self::assertSame([self::PARENT_ID], $fact?->ancestorIds);
    }

    private function category(string $id, ?string $parentId, bool $archived = false, string $workspace = self::WORKSPACE): Category
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new Category(
            id: $id,
            workspace: WorkspaceScope::fromString($workspace),
            type: CategoryType::EXPENSE,
            label: 'Category',
            parentId: $parentId,
            icon: null,
            color: null,
            defaultAnalyticAxes: [],
            budgetIncluded: true,
            sortOrder: 0,
            depth: null === $parentId ? 1 : 2,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            archivedAt: $archived ? $now : null,
        );
    }
}
