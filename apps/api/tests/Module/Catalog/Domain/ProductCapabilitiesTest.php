<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCapability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductCapabilitiesTest extends TestCase
{
    public function testEveryDocumentedCapabilityIsRepresentedInStableOrder(): void
    {
        $capabilities = ProductCapabilities::fromStrings([
            'SUPPORTS_LIABILITY',
            'SUPPORTS_BALANCE',
            'SUPPORTS_TRANSACTIONS',
        ]);

        self::assertSame([
            ProductCapability::SUPPORTS_BALANCE,
            ProductCapability::SUPPORTS_TRANSACTIONS,
            ProductCapability::SUPPORTS_LIABILITY,
        ], $capabilities->all());
        self::assertSame([
            'SUPPORTS_BALANCE',
            'SUPPORTS_TRANSACTIONS',
            'SUPPORTS_LIABILITY',
        ], $capabilities->toStrings());
        self::assertSame([
            'SUPPORTS_BALANCE',
            'SUPPORTS_TRANSACTIONS',
            'SUPPORTS_INTEREST',
            'SUPPORTS_HOLDINGS',
            'SUPPORTS_TRADES',
            'SUPPORTS_ARBITRAGE',
            'SUPPORTS_CONTRIBUTIONS',
            'SUPPORTS_FEES',
            'SUPPORTS_TAX_TRACKING',
            'SUPPORTS_LIABILITY',
        ], array_column(ProductCapability::cases(), 'value'));
    }

    /** @param list<string> $capabilities */
    #[DataProvider('invalidCombinations')]
    public function testAnUnusableCombinationIsRefusedWithAnActionableReason(
        array $capabilities,
        string $reason,
    ): void {
        $this->expectException(InvalidCatalogEntry::class);
        $this->expectExceptionMessage($reason);

        ProductCapabilities::fromStrings($capabilities);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidCombinations(): iterable
    {
        yield 'empty product' => [[], 'A product declares at least one capability.'];
        yield 'interest without balance' => [
            ['SUPPORTS_INTEREST'],
            'SUPPORTS_INTEREST requires SUPPORTS_BALANCE.',
        ];
        yield 'holdings without balance' => [
            ['SUPPORTS_HOLDINGS'],
            'SUPPORTS_HOLDINGS requires SUPPORTS_BALANCE.',
        ];
        yield 'trades without holdings' => [
            ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS', 'SUPPORTS_TRADES'],
            'SUPPORTS_TRADES requires SUPPORTS_HOLDINGS.',
        ];
        yield 'trades without transactions' => [
            ['SUPPORTS_BALANCE', 'SUPPORTS_HOLDINGS', 'SUPPORTS_TRADES'],
            'SUPPORTS_TRADES requires SUPPORTS_TRANSACTIONS.',
        ];
        yield 'arbitrage without holdings' => [
            ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS', 'SUPPORTS_ARBITRAGE'],
            'SUPPORTS_ARBITRAGE requires SUPPORTS_HOLDINGS.',
        ];
        yield 'arbitrage without transactions' => [
            ['SUPPORTS_BALANCE', 'SUPPORTS_HOLDINGS', 'SUPPORTS_ARBITRAGE'],
            'SUPPORTS_ARBITRAGE requires SUPPORTS_TRANSACTIONS.',
        ];
        yield 'contributions without transactions' => [
            ['SUPPORTS_BALANCE', 'SUPPORTS_CONTRIBUTIONS'],
            'SUPPORTS_CONTRIBUTIONS requires SUPPORTS_TRANSACTIONS.',
        ];
        yield 'fees without transactions' => [
            ['SUPPORTS_BALANCE', 'SUPPORTS_FEES'],
            'SUPPORTS_FEES requires SUPPORTS_TRANSACTIONS.',
        ];
        yield 'tax tracking without transactions' => [
            ['SUPPORTS_BALANCE', 'SUPPORTS_TAX_TRACKING'],
            'SUPPORTS_TAX_TRACKING requires SUPPORTS_TRANSACTIONS.',
        ];
        yield 'liability without balance' => [
            ['SUPPORTS_LIABILITY'],
            'SUPPORTS_LIABILITY requires SUPPORTS_BALANCE.',
        ];
        yield 'duplicate capability' => [
            ['SUPPORTS_BALANCE', 'SUPPORTS_BALANCE'],
            'SUPPORTS_BALANCE is declared more than once.',
        ];
        yield 'unknown capability' => [
            ['SUPPORTS_BALANCE', 'ADMIN_OVERRIDE'],
            'ADMIN_OVERRIDE is not a supported product capability.',
        ];
    }
}
