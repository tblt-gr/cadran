<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\FinancialProduct;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FinancialProductTest extends TestCase
{
    public function testAGenericProductMayBelongToNoJurisdiction(): void
    {
        $product = self::product(jurisdiction: null);

        self::assertNull($product->jurisdiction);
    }

    #[DataProvider('refusedDefinitions')]
    public function testADefinitionThatCouldNotBeReadBackIsRefused(string $displayName, ?string $jurisdiction, ?string $groupCode, int $version): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        self::product($displayName, $jurisdiction, $groupCode, $version);
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, int}>
     */
    public static function refusedDefinitions(): iterable
    {
        yield 'an empty name' => ['', 'FR', 'LIQUIDITY_SAVINGS', 1];
        yield 'an untrimmed name' => [' Livret A', 'FR', 'LIQUIDITY_SAVINGS', 1];
        yield 'an overlong name' => [str_repeat('a', 81), 'FR', 'LIQUIDITY_SAVINGS', 1];
        yield 'a lowercase jurisdiction' => ['Livret A', 'fr', 'LIQUIDITY_SAVINGS', 1];
        yield 'a three-letter jurisdiction' => ['Livret A', 'FRA', 'LIQUIDITY_SAVINGS', 1];
        yield 'a lowercase group code' => ['Livret A', 'FR', 'liquidity', 1];
        yield 'a version below one' => ['Livret A', 'FR', 'LIQUIDITY_SAVINGS', 0];
    }

    private static function product(
        string $displayName = 'Livret A',
        ?string $jurisdiction = 'FR',
        ?string $defaultGroupCode = 'LIQUIDITY_SAVINGS',
        int $catalogVersion = 1,
    ): FinancialProduct {
        return new FinancialProduct(
            code: ProductCode::fromString('FR_LIVRET_A'),
            displayName: $displayName,
            jurisdiction: $jurisdiction,
            accountKind: AccountKind::SAVINGS,
            wrapperKind: WrapperKind::REGULATED_SAVINGS,
            yieldKind: YieldKind::REGULATED_RATE,
            defaultGroupCode: $defaultGroupCode,
            catalogVersion: $catalogVersion,
        );
    }
}
