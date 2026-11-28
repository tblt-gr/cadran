<?php

declare(strict_types=1);

namespace App\Module\Catalog\Application;

use App\Module\Foundation\Application\PageRequest;
use App\Module\Foundation\Domain\BusinessDay;
use App\Module\Foundation\Domain\InvalidBusinessDay;
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

    public function __construct(
        private ProductCatalog $catalog,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?int $page, ?int $pageSize, ?string $asOf): ProductPage
    {
        $request = PageRequest::tryFrom($page, $pageSize, self::DEFAULT_PAGE_SIZE)
            ?? throw new InvalidProductQuery(sprintf('The page number must be between 1 and %d and the page size between 1 and %d.', PageRequest::MAX_PAGE, PageRequest::MAX_PAGE_SIZE));

        $today = BusinessDay::fromDateTime($this->clock->now());
        $businessDay = null === $asOf ? $today : self::parse($asOf);

        $products = [];
        foreach ($this->catalog->readPage($request->pageSize, $request->offset()) as $entry) {
            $products[] = $entry->effectiveOn($businessDay->date, $today->date);
        }

        return new ProductPage(
            products: $products,
            page: $request->page,
            pageSize: $request->pageSize,
            total: $this->catalog->count(),
        );
    }

    private static function parse(string $asOf): BusinessDay
    {
        try {
            return BusinessDay::fromIsoDate($asOf);
        } catch (InvalidBusinessDay $failure) {
            throw new InvalidProductQuery($failure->getMessage(), previous: $failure);
        }
    }
}
