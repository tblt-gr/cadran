<?php

declare(strict_types=1);

namespace App\Module\Catalog\Application;

use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reads one page of the product catalogue as it stands on a business date.
 *
 * The catalogue is global, so there is no workspace to resolve; the bounds
 * that matter are on the volume one request may read and on the date it may
 * ask about.
 */
final readonly class ListProducts
{
    public const int DEFAULT_PAGE_SIZE = 25;
    public const int MAX_PAGE_SIZE = 100;
    /** Caps the offset, so a page number cannot make PostgreSQL walk the table. */
    public const int MAX_PAGE = 1000;

    public function __construct(
        private ProductCatalog $catalog,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?int $page, ?int $perPage, ?string $asOf): ProductPage
    {
        $requestedPage = $page ?? 1;
        $pageSize = $perPage ?? self::DEFAULT_PAGE_SIZE;

        if ($requestedPage < 1 || $requestedPage > self::MAX_PAGE) {
            throw new InvalidProductQuery(sprintf('The page number must be between 1 and %d.', self::MAX_PAGE));
        }

        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidProductQuery(sprintf('The page size must be between 1 and %d.', self::MAX_PAGE_SIZE));
        }

        $today = BusinessDay::fromDateTime($this->clock->now());
        $businessDay = null === $asOf ? $today : self::parse($asOf);

        $products = [];
        foreach ($this->catalog->readPage($pageSize, ($requestedPage - 1) * $pageSize) as $entry) {
            $products[] = $entry->effectiveOn($businessDay->date, $today->date);
        }

        return new ProductPage(
            products: $products,
            page: $requestedPage,
            perPage: $pageSize,
            total: $this->catalog->count(),
        );
    }

    private static function parse(string $asOf): BusinessDay
    {
        try {
            return BusinessDay::fromIsoDate($asOf);
        } catch (InvalidCatalogEntry $failure) {
            throw new InvalidProductQuery($failure->getMessage(), previous: $failure);
        }
    }
}
