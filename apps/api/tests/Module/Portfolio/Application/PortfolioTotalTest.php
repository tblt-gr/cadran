<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Application;

use App\Module\Portfolio\Application\PortfolioTotal;
use App\Module\Portfolio\Application\PortfolioUnrealizedGain;
use PHPUnit\Framework\TestCase;

final class PortfolioTotalTest extends TestCase
{
    public function testTwoAccountsSumExactlyAndPerformanceIsGainOverCostToSixDecimals(): void
    {
        $total = PortfolioTotal::of([
            self::account('3500.00', '500.00', '3000.00'),
            self::account('1000.00', '-100.00', '1100.00'),
        ]);
        self::assertSame('4500.00', $total['totalValue']);
        self::assertSame('400.00', $total['unrealizedGain']);
        self::assertSame('4100.00', $total['costBasis']);
        self::assertSame('0.097561', $total['performance']);
        self::assertSame(2, $total['valuedAccounts']);
        self::assertSame(0, $total['unvaluedAccounts']);
        self::assertNull($total['reason']);
        self::assertSame('EUR', $total['assetCode']);
    }

    public function testOldestValuationDateAndPartialCountOnlyCoverValuedAccounts(): void
    {
        $total = PortfolioTotal::of([
            self::account('10.00', '1.00', '9.00', 'EUR', '2026-09-30', true),
            self::account('20.00', '2.00', '18.00', 'EUR', '2026-08-31'),
            self::account(null, null, null, 'EUR', '2026-01-31', true),
        ]);
        self::assertSame('2026-08-31', $total['oldestValuedOn']);
        self::assertSame(1, $total['partialNewerAccounts']);
        $none = PortfolioTotal::of([self::account(null, null, null)]);
        self::assertNull($none['oldestValuedOn']);
        self::assertSame(0, $none['partialNewerAccounts']);
    }

    public function testNegativePerformanceRoundsHalfAwayFromZero(): void
    {
        self::assertSame('-0.333333', PortfolioUnrealizedGain::performance('-100.00', '300.00'));
        self::assertSame('0.000001', PortfolioUnrealizedGain::performance('0.0000005', '1'));
        self::assertSame('-0.000001', PortfolioUnrealizedGain::performance('-0.0000005', '1'));
    }

    public function testPerformanceIsNullWithoutAGainOrWithAZeroCost(): void
    {
        self::assertNull(PortfolioUnrealizedGain::performance(null, '10'));
        self::assertNull(PortfolioUnrealizedGain::performance('10', null));
        self::assertNull(PortfolioUnrealizedGain::performance('10', '0.00'));
        self::assertSame('0.000000', PortfolioUnrealizedGain::performance('0', '10'));
    }

    public function testZeroCostKeepsGainAndCostButNoPerformance(): void
    {
        $total = PortfolioTotal::of([self::account('100.00', '100.00', '0.00')]);
        self::assertSame('100.00', $total['unrealizedGain']);
        self::assertSame('0.00', $total['costBasis']);
        self::assertNull($total['performance']);
        self::assertNull($total['reason']);
    }

    public function testNoAccountOrNoValuationIsNullNeverZero(): void
    {
        $none = PortfolioTotal::of([]);
        self::assertNull($none['assetCode']);
        self::assertNull($none['totalValue']);
        self::assertSame('NO_VALUATION', $none['reason']);
        self::assertSame(0, $none['valuedAccounts']);

        $unvalued = PortfolioTotal::of([self::account(null, null, null), self::account(null, null, null)]);
        self::assertNull($unvalued['totalValue']);
        self::assertNull($unvalued['unrealizedGain']);
        self::assertSame('NO_VALUATION', $unvalued['reason']);
        self::assertSame(2, $unvalued['unvaluedAccounts']);
        self::assertSame('EUR', $unvalued['assetCode']);
    }

    public function testAnUnvaluedAccountIsCountedButDoesNotBlockTheGain(): void
    {
        $total = PortfolioTotal::of([self::account('3500.00', '500.00', '3000.00'), self::account(null, null, null)]);
        self::assertSame('3500.00', $total['totalValue']);
        self::assertSame('500.00', $total['unrealizedGain']);
        self::assertSame('0.166667', $total['performance']);
        self::assertSame(1, $total['valuedAccounts']);
        self::assertSame(1, $total['unvaluedAccounts']);
        self::assertNull($total['reason']);
    }

    public function testAValuedAccountWithoutAComputableGainNullsGainCostAndPerformance(): void
    {
        $total = PortfolioTotal::of([self::account('3500.00', '500.00', '3000.00'), self::account('1000.00', null, null)]);
        self::assertSame('4500.00', $total['totalValue']);
        self::assertNull($total['unrealizedGain']);
        self::assertNull($total['costBasis']);
        self::assertNull($total['performance']);
        self::assertSame('INCOMPLETE_GAIN', $total['reason']);
    }

    public function testMixedAssetsNullEverything(): void
    {
        $total = PortfolioTotal::of([self::account('10.00', '1.00', '9.00'), self::account('5.00', '1.00', '4.00', 'BTC')]);
        self::assertNull($total['assetCode']);
        self::assertNull($total['totalValue']);
        self::assertNull($total['unrealizedGain']);
        self::assertNull($total['performance']);
        self::assertSame('MIXED_ASSETS', $total['reason']);
        self::assertSame(2, $total['valuedAccounts']);
    }

    public function testVeryLargeDecimalsStayExact(): void
    {
        $big = '99999999999999999999999999.999999999999999999999999';
        $total = PortfolioTotal::of([self::account($big, $big, $big), self::account('0.000000000000000000000001', '0.000000000000000000000001', '0.000000000000000000000001')]);
        self::assertSame('100000000000000000000000000.000000000000000000000000', $total['totalValue']);
        self::assertSame('100000000000000000000000000.000000000000000000000000', $total['unrealizedGain']);
        self::assertSame('1.000000', $total['performance']);
    }

    /** @return array{assetCode: string, totalValue: ?string, gain: ?string, costBasis: ?string, valuedOn: ?string, newerPartial: bool} */
    private static function account(?string $total, ?string $gain, ?string $cost, string $asset = 'EUR', ?string $valuedOn = null, bool $newerPartial = false): array
    {
        return ['assetCode' => $asset, 'totalValue' => $total, 'gain' => $gain, 'costBasis' => $cost,
            'valuedOn' => $valuedOn ?? (null === $total ? null : '2026-03-31'), 'newerPartial' => $newerPartial];
    }
}
