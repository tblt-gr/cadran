<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Application;

use App\Module\Catalog\Application\InvalidProductQuery;
use App\Module\Catalog\Application\ProductNotFound;
use App\Module\Catalog\Application\ReadProduct;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Catalog\Domain\VerificationState;
use App\Tests\Module\Catalog\Application\Double\InMemoryProductCatalog;
use App\Tests\Module\Catalog\Domain\CatalogFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReadProductTest extends TestCase
{
    public function testAProductIsReadWithTheRulesEffectiveOnTheBusinessDate(): void
    {
        $product = ($this->readProduct())('FR_LIVRET_A', '2026-09-02');

        self::assertSame('Livret A', $product->product->displayName);
        self::assertSame('22950.00', $product->rules[0]->rule->value->amount?->value->toString());
        self::assertSame('EUR', $product->rules[0]->rule->value->amount->asset->toString());
        self::assertSame(VerificationState::VERIFIED, $product->rules[0]->verification);
    }

    public function testAStaleVerificationIsGradedAgainstTheClockNotTheBusinessDate(): void
    {
        $readProduct = new ReadProduct(
            new InMemoryProductCatalog([$this->livretA()]),
            new MockClock('2030-01-01 00:00:00', 'UTC'),
        );

        $product = $readProduct('FR_LIVRET_A', '2026-09-02');

        self::assertSame(VerificationState::STALE, $product->rules[0]->verification);
    }

    #[DataProvider('unknownCodes')]
    public function testAnUnknownAndAMalformedCodeAnswerAlike(string $code): void
    {
        $this->expectException(ProductNotFound::class);

        ($this->readProduct())($code, null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownCodes(): iterable
    {
        yield 'never seeded' => ['FR_PEL'];
        yield 'lowercase' => ['fr_livret_a'];
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'sql fragment' => ["FR_LIVRET_A' OR '1'='1"];
    }

    public function testAMalformedBusinessDateIsRefusedBeforeReadingAnything(): void
    {
        $this->expectException(InvalidProductQuery::class);

        ($this->readProduct())('FR_LIVRET_A', '02/09/2026');
    }

    private function readProduct(): ReadProduct
    {
        return new ReadProduct(
            new InMemoryProductCatalog([$this->livretA()]),
            new MockClock('2026-09-02 08:30:00', 'UTC'),
        );
    }

    private function livretA(): CatalogEntry
    {
        return new CatalogEntry(
            CatalogFixture::product(),
            new RuleSchedule([
                CatalogFixture::ceiling('22950.00', '2025-04-25', null),
                CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
            ]),
        );
    }
}
