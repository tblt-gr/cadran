<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Foundation\Application\CallerWorkspace;

/**
 * Reads one page of the models of the calling workspace.
 *
 * Archived models are out of the page unless they are asked for: archiving is
 * what takes a model out of the working set, and a picker offering one would
 * be offering new use of something the workspace retired.
 */
final readonly class ListProductModels
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;
    /** Caps the offset, so a page number cannot make PostgreSQL walk the table. */
    public const int MAX_PAGE = 1000;

    public function __construct(
        private CallerWorkspace $caller,
        private ProductModelRepository $models,
    ) {
    }

    public function __invoke(bool $includeArchived, ?int $page, ?int $perPage): ProductModelPage
    {
        $requestedPage = $page ?? 1;
        $pageSize = $perPage ?? self::DEFAULT_PAGE_SIZE;
        if ($requestedPage < 1 || $requestedPage > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidProductModelInput('The model page is outside its bounds.');
        }

        $workspace = $this->caller->resolve();

        return new ProductModelPage(
            items: array_map(
                ProductModelView::fromModel(...),
                $this->models->list($workspace, $includeArchived, $pageSize, ($requestedPage - 1) * $pageSize),
            ),
            page: $requestedPage,
            perPage: $pageSize,
            total: $this->models->count($workspace, $includeArchived),
        );
    }
}
