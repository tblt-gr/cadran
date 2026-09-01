<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Application\CallerWorkspace;

final readonly class ListCategories
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;
    public const int MAX_PAGE = 1000;

    public function __construct(
        private CallerWorkspace $caller,
        private CategoryRepository $categories,
    ) {
    }

    public function __invoke(
        bool $includeArchived,
        ?int $page,
        ?int $perPage,
        ?string $type = null,
        ?string $search = null,
        bool $parentEligible = false,
    ): CategoryPage {
        $requestedPage = $page ?? 1;
        $pageSize = $perPage ?? self::DEFAULT_PAGE_SIZE;
        if ($requestedPage < 1 || $requestedPage > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidCategoryInput('The category page is outside its bounds.');
        }
        $categoryType = null === $type ? null : CategoryInputParser::type($type);
        $trimmedSearch = null === $search ? null : trim($search);
        if (null !== $trimmedSearch && (mb_strlen($trimmedSearch) > 80 || 1 === preg_match('/[\p{Cc}\p{Cf}]/u', $trimmedSearch))) {
            throw new InvalidCategoryInput('The category search is invalid.');
        }

        $workspace = $this->caller->resolve();
        $categories = $this->categories->list(
            $workspace,
            $includeArchived,
            $pageSize,
            ($requestedPage - 1) * $pageSize,
            $categoryType,
            $trimmedSearch,
            $parentEligible,
        );
        $parentIds = array_flip($this->categories->parentIdsWithChildren($workspace, array_column($categories, 'id')));
        // Parents can sit on another page, so their labels are resolved by identifier rather than
        // read from the current page.
        $parentLabels = $this->categories->labelsByIds(
            $workspace,
            array_values(array_unique(array_filter(array_column($categories, 'parentId')))),
        );

        return new CategoryPage(
            items: array_map(
                static fn ($category): CategoryView => CategoryView::fromCategory(
                    $category,
                    isset($parentIds[$category->id]),
                    null === $category->parentId ? null : ($parentLabels[$category->parentId] ?? null),
                ),
                $categories,
            ),
            page: $requestedPage,
            perPage: $pageSize,
            total: $this->categories->count($workspace, $includeArchived, $categoryType, $trimmedSearch, $parentEligible),
        );
    }
}
