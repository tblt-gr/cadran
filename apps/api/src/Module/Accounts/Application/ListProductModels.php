<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\PageRequest;

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

    public function __construct(
        private CallerWorkspace $caller,
        private ProductModelRepository $models,
    ) {
    }

    public function __invoke(bool $includeArchived, ?int $page, ?int $pageSize): ProductModelPage
    {
        $request = PageRequest::tryFrom($page, $pageSize, self::DEFAULT_PAGE_SIZE)
            ?? throw new InvalidProductModelInput('The model page is outside its bounds.');

        $workspace = $this->caller->resolve();

        return new ProductModelPage(
            items: array_map(
                ProductModelView::fromModel(...),
                $this->models->list($workspace, $includeArchived, $request->pageSize, $request->offset()),
            ),
            page: $request->page,
            pageSize: $request->pageSize,
            total: $this->models->count($workspace, $includeArchived),
        );
    }
}
