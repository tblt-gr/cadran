<?php

declare(strict_types=1);

namespace App\Tests\Module\Reference\Application;

use App\Module\Reference\Application\InvalidAssetQuery;
use App\Module\Reference\Application\ListAssets;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reference catalogue is small, global and read-only, so it pages the
 * classic way. What matters here is that the page it serves stays bounded: no
 * caller can ask for an unbounded read or an arbitrary offset.
 */
final class ListAssetsTest extends TestCase
{
    public function testTheFirstPageIsServedWithoutAnyQueryParameter(): void
    {
        $catalog = InMemoryAssetCatalog::withCodes('EUR', 'USD', 'BTC');

        $page = (new ListAssets($catalog))(null, null);

        // By code, like the real catalogue, whatever order they were seeded in.
        self::assertSame(['BTC', 'EUR', 'USD'], self::codesOf($page->assets));
        self::assertSame(1, $page->page);
        self::assertSame(ListAssets::DEFAULT_PAGE_SIZE, $page->perPage);
        self::assertSame(3, $page->total);
    }

    public function testAPageReadsItsOwnSliceOfTheCatalogue(): void
    {
        $catalog = InMemoryAssetCatalog::withCodes('EUR', 'USD', 'BTC', 'ETH', 'JPY');

        $page = (new ListAssets($catalog))(2, 2);

        self::assertSame(['EUR', 'JPY'], self::codesOf($page->assets));
        self::assertSame(2, $page->page);
        self::assertSame(2, $page->perPage);
        self::assertSame(5, $page->total);
    }

    public function testAPageBeyondTheCatalogueIsEmptyRatherThanAnError(): void
    {
        $catalog = InMemoryAssetCatalog::withCodes('EUR');

        $page = (new ListAssets($catalog))(4, 10);

        self::assertSame([], $page->assets);
        self::assertSame(1, $page->total);
    }

    #[DataProvider('unboundedQueries')]
    public function testAnUnboundedQueryIsRefused(?int $page, ?int $perPage): void
    {
        $catalog = InMemoryAssetCatalog::withCodes('EUR');

        $this->expectException(InvalidAssetQuery::class);

        (new ListAssets($catalog))($page, $perPage);
    }

    /**
     * @return iterable<string, array{int|null, int|null}>
     */
    public static function unboundedQueries(): iterable
    {
        yield 'a page size above the ceiling' => [1, ListAssets::MAX_PAGE_SIZE + 1];
        yield 'a page size of zero' => [1, 0];
        yield 'a negative page size' => [1, -1];
        yield 'a page below one' => [0, 10];
        yield 'a negative page' => [-3, 10];
        yield 'a page beyond the offset ceiling' => [ListAssets::MAX_PAGE + 1, 10];
    }

    /**
     * @param list<\App\Module\Reference\Domain\Asset> $assets
     *
     * @return list<string>
     */
    private static function codesOf(array $assets): array
    {
        return array_map(static fn ($asset): string => $asset->code->toString(), $assets);
    }
}
