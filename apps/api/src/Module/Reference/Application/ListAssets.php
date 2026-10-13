<?php

declare(strict_types=1);

namespace App\Module\Reference\Application;

/**
 * Reads one page of the system asset reference. The catalogue is global, so
 * there is no workspace to resolve here; the bound that matters is on the
 * volume a single request may read.
 */
final readonly class ListAssets
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;
    /** Caps the offset, so a page number cannot make PostgreSQL walk the table. */
    public const int MAX_PAGE = 1000;

    public function __construct(private AssetCatalog $catalog)
    {
    }

    public function __invoke(?int $page, ?int $pageSize): AssetPage
    {
        $requestedPage = $page ?? 1;
        $resolvedPageSize = $pageSize ?? self::DEFAULT_PAGE_SIZE;

        if ($requestedPage < 1 || $requestedPage > self::MAX_PAGE) {
            throw new InvalidAssetQuery(sprintf('The page number must be between 1 and %d.', self::MAX_PAGE));
        }

        if ($resolvedPageSize < 1 || $resolvedPageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidAssetQuery(sprintf('The page size must be between 1 and %d.', self::MAX_PAGE_SIZE));
        }

        return new AssetPage(
            assets: $this->catalog->readPage($resolvedPageSize, ($requestedPage - 1) * $resolvedPageSize),
            page: $requestedPage,
            pageSize: $resolvedPageSize,
            total: $this->catalog->count(),
        );
    }
}
