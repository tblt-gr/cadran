<?php

declare(strict_types=1);

namespace App\Module\Reference\Application;

use App\Module\Foundation\Application\PageRequest;

/**
 * Reads one page of the system asset reference. The catalogue is global, so
 * there is no workspace to resolve here; the bound that matters is on the
 * volume a single request may read.
 */
final readonly class ListAssets
{
    public const int DEFAULT_PAGE_SIZE = 50;

    public function __construct(private AssetCatalog $catalog)
    {
    }

    public function __invoke(?int $page, ?int $pageSize): AssetPage
    {
        $request = PageRequest::tryFrom($page, $pageSize, self::DEFAULT_PAGE_SIZE)
            ?? throw new InvalidAssetQuery(sprintf('The page number must be between 1 and %d and the page size between 1 and %d.', PageRequest::MAX_PAGE, PageRequest::MAX_PAGE_SIZE));

        return new AssetPage(
            assets: $this->catalog->readPage($request->pageSize, $request->offset()),
            page: $request->page,
            pageSize: $request->pageSize,
            total: $this->catalog->count(),
        );
    }
}
