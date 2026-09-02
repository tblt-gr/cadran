<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\FinancialProduct;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCapability;
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

    public function testALiabilityProductDeclaresItsLiabilityCapability(): void
    {
        $product = self::product(
            accountKind: AccountKind::LIABILITY,
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_LIABILITY,
            ),
        );

        self::assertTrue($product->capabilities->contains(ProductCapability::SUPPORTS_LIABILITY));
    }

    public function testALiabilityKindWithoutTheCapabilityIsRefused(): void
    {
        $this->expectException(InvalidCatalogEntry::class);
        $this->expectExceptionMessage('A LIABILITY product requires SUPPORTS_LIABILITY.');

        self::product(
            accountKind: AccountKind::LIABILITY,
            capabilities: ProductCapabilities::of(ProductCapability::SUPPORTS_BALANCE),
        );
    }

    public function testTheLiabilityCapabilityIsRefusedOnAnAssetProduct(): void
    {
        $this->expectException(InvalidCatalogEntry::class);
        $this->expectExceptionMessage('SUPPORTS_LIABILITY is exclusive to LIABILITY products.');

        self::product(capabilities: ProductCapabilities::of(
            ProductCapability::SUPPORTS_BALANCE,
            ProductCapability::SUPPORTS_LIABILITY,
        ));
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
        AccountKind $accountKind = AccountKind::SAVINGS,
        ?ProductCapabilities $capabilities = null,
    ): FinancialProduct {
        return new FinancialProduct(
            code: ProductCode::fromString('FR_LIVRET_A'),
            displayName: $displayName,
            jurisdiction: $jurisdiction,
            accountKind: $accountKind,
            wrapperKind: WrapperKind::REGULATED_SAVINGS,
            yieldKind: YieldKind::REGULATED_RATE,
            defaultGroupCode: $defaultGroupCode,
            capabilities: $capabilities ?? ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_INTEREST,
            ),
            catalogVersion: $catalogVersion,
        );
    }
}
