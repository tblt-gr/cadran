<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Application;

use App\Module\Catalog\Application\InvalidProductQuery;
use App\Module\Catalog\Application\ListProducts;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Catalog\Domain\YieldKind;
use App\Tests\Module\Catalog\Application\Double\InMemoryProductCatalog;
use App\Tests\Module\Catalog\Domain\CatalogFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ListProductsTest extends TestCase
{
    public function testAPageDefaultsToTodayAndReportsTheCatalogueSize(): void
    {
        $page = ($this->listProducts())(null, null, null);

        self::assertSame(1, $page->page);
        self::assertSame(ListProducts::DEFAULT_PAGE_SIZE, $page->perPage);
        self::assertSame(2, $page->total);
        self::assertSame('2026-09-02', $page->products[0]->asOf->format('Y-m-d'));
    }

    public function testAPastBusinessDateResolvesThePeriodThatAppliedThen(): void
    {
        $page = ($this->listProducts())(null, null, '2026-03-15');

        $livretA = $page->products[1];
        self::assertSame('FR_LIVRET_A', $livretA->product->code->toString());
        self::assertSame('2.4', $livretA->rules[1]->rule->value->percentage?->toString());
        self::assertSame([], $livretA->unavailableRuleKinds);
    }

    public function testADateWithNoSourcedRateReportsItUnavailableRatherThanZero(): void
    {
        $page = ($this->listProducts())(null, null, '2027-06-30');

        self::assertSame([RuleKind::ANNUAL_RATE], $page->products[1]->unavailableRuleKinds);
    }

    public function testAMarketProductNeverCarriesARate(): void
    {
        $page = ($this->listProducts())(null, null, null);

        $cto = $page->products[0];
        self::assertSame('FR_CTO', $cto->product->code->toString());
        self::assertFalse($cto->product->yieldKind->isGuaranteed());
        self::assertSame([], $cto->unavailableRuleKinds);
        self::assertSame([], $cto->rules);
    }

    public function testAPageStaysInsideItsBounds(): void
    {
        $page = ($this->listProducts())(2, 1, null);

        self::assertCount(1, $page->products);
        self::assertSame('FR_LIVRET_A', $page->products[0]->product->code->toString());
    }

    #[DataProvider('unboundedQueries')]
    public function testAnUnboundedQueryIsRefused(?int $page, ?int $perPage, ?string $asOf): void
    {
        $this->expectException(InvalidProductQuery::class);

        ($this->listProducts())($page, $perPage, $asOf);
    }

    /**
     * @return iterable<string, array{?int, ?int, ?string}>
     */
    public static function unboundedQueries(): iterable
    {
        yield 'page zero' => [0, null, null];
        yield 'page past the cap' => [1001, null, null];
        yield 'page size zero' => [null, 0, null];
        yield 'page size past the cap' => [null, 101, null];
        yield 'a date that is not a day' => [null, null, '2026-09'];
        yield 'a day that does not exist' => [null, null, '2026-02-31'];
        yield 'a day outside the calendar bounds' => [null, null, '1899-12-31'];
        yield 'a timestamp instead of a day' => [null, null, '2026-09-02T00:00:00Z'];
    }

    private function listProducts(): ListProducts
    {
        return new ListProducts(
            new InMemoryProductCatalog([
                new CatalogEntry(
                    CatalogFixture::product(YieldKind::REGULATED_RATE),
                    new RuleSchedule([
                        CatalogFixture::ceiling('22950.00', '2025-04-25', null),
                        CatalogFixture::rate('2.4', '2026-02-01', '2026-07-31'),
                        CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
                    ]),
                ),
                new CatalogEntry(CatalogFixture::marketProduct(), RuleSchedule::empty()),
            ]),
            new MockClock('2026-09-02 08:30:00', 'UTC'),
        );
    }
}
