<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductCodeTest extends TestCase
{
    public function testACanonicalCodeIsKeptAsWritten(): void
    {
        self::assertSame('FR_LIVRET_A', ProductCode::fromString('FR_LIVRET_A')->toString());
    }

    public function testTwoCodesWithTheSameTextAreTheSameProduct(): void
    {
        self::assertTrue(ProductCode::fromString('FR_PEA')->equals(ProductCode::fromString('FR_PEA')));
        self::assertFalse(ProductCode::fromString('FR_PEA')->equals(ProductCode::fromString('FR_CTO')));
    }

    #[DataProvider('refusedCodes')]
    public function testACodeThatCouldSplitOneProductInTwoIsRefused(string $code): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        ProductCode::fromString($code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedCodes(): iterable
    {
        yield 'lowercase' => ['fr_livret_a'];
        yield 'too short' => ['FR'];
        yield 'leading digit' => ['1FR_PEA'];
        yield 'trailing underscore' => ['FR_PEA_'];
        yield 'double underscore' => ['FR__PEA'];
        yield 'spaced' => ['FR PEA'];
        yield 'hyphenated' => ['FR-PEA'];
        yield 'too long' => [str_repeat('A', 33)];
        yield 'newline smuggled in' => ["FR_PEA\n"];
    }
}
