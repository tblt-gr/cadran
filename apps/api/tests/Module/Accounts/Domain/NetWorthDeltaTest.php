<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\NetWorth;
use App\Module\Accounts\Domain\NetWorthCalculator;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\NetWorthDelta;
use App\Module\Accounts\Domain\NetWorthReason;
use App\Module\Accounts\Domain\ValuationQuality;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\TestCase;

/**
 * Hand-checked reference for the movement between two dated aggregates.
 *
 * 31 August 2026: +120 000.00 EUR
 *  5 September 2026: +124 680.00 EUR
 *
 * delta = 124 680.00 - 120 000.00 = +4 680.00 EUR
 * rate  = 4 680.00 / 120 000.00 = 0.039 = 3.9 %
 */
final class NetWorthDeltaTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    public function testTheDeltaAndItsRateComeFromTheTwoTotals(): void
    {
        $delta = NetWorthDelta::between(self::netWorth('124680.00', '2026-09-05'), self::netWorth('120000.00', '2026-08-31'));

        self::assertSame('2026-08-31', $delta->comparedOn->format('Y-m-d'));
        self::assertSame('120000.00', $delta->previousTotal?->toString());
        self::assertSame('4680.00', $delta->amount?->toString());
        self::assertSame('0.039000000000000000000000', $delta->rate?->toString());
        self::assertSame('3.900000000000000000000000', $delta->ratePercent?->toString());
        self::assertNull($delta->rateReason);
    }

    public function testAZeroBaseKeepsTheDeltaAndDropsTheRate(): void
    {
        $delta = NetWorthDelta::between(self::netWorth('5000.00', '2026-09-05'), self::netWorth('0.00', '2026-08-31'));

        self::assertSame('5000.00', $delta->amount?->toString());
        self::assertNull($delta->amountReason);
        self::assertNull($delta->rate);
        self::assertNull($delta->ratePercent);
        self::assertSame(NetWorthReason::ZERO_BASE, $delta->rateReason);
    }

    public function testANegativeBaseKeepsTheDeltaAndDropsTheRate(): void
    {
        $delta = NetWorthDelta::between(self::netWorth('-150000.00', '2026-09-05'), self::netWorth('-202050.00', '2026-08-31'));

        self::assertSame('52050.00', $delta->amount?->toString());
        self::assertNull($delta->rate);
        self::assertSame(NetWorthReason::NEGATIVE_BASE, $delta->rateReason);
    }

    public function testANonCalculableSidePropagatesItsReasonToBothFigures(): void
    {
        $delta = NetWorthDelta::between(self::netWorth('124680.00', '2026-09-05'), self::withoutValuation('2026-08-31'));

        self::assertNull($delta->amount);
        self::assertSame(NetWorthReason::MISSING_VALUATION, $delta->previousReason);
        self::assertSame(NetWorthReason::MISSING_VALUATION, $delta->amountReason);
        self::assertSame(NetWorthReason::MISSING_VALUATION, $delta->rateReason);
        self::assertNull($delta->previousTotal);
    }

    public function testTwoAssetsAreNotSubtractedFromEachOther(): void
    {
        $delta = NetWorthDelta::between(
            self::netWorth('124680.00', '2026-09-05'),
            self::netWorth('3.00000000', '2026-08-31', asset: 'BTC'),
        );

        self::assertNull($delta->amount);
        self::assertSame(NetWorthReason::MIXED_ASSETS, $delta->amountReason);
        self::assertSame(NetWorthReason::MIXED_ASSETS, $delta->rateReason);

        // Each side keeps the unit it was measured in: publishing the previous
        // total under the current asset would show 3 BTC as 3 EUR.
        self::assertSame('3.00000000', $delta->previousTotal?->toString());
        self::assertSame('BTC', $delta->previousAsset?->toString());
        self::assertSame('EUR', $delta->asset?->toString());
    }

    private static function netWorth(string $total, string $on, string $asset = 'EUR'): NetWorth
    {
        $sign = str_starts_with($total, '-') ? -1 : 1;

        return NetWorthCalculator::compute(
            [new NetWorthContribution(
                accountId: self::ACCOUNT,
                netWorthSign: $sign,
                primaryGroupId: null,
                tagGroupIds: [],
                amount: new AssetAmount(DecimalValue::fromString(ltrim($total, '-')), AssetCode::fromString($asset)),
                quality: ValuationQuality::CURRENT,
                ageDays: 0,
                valuedOn: new \DateTimeImmutable($on, new \DateTimeZone('UTC')),
                eligible: true,
            )],
            new \DateTimeImmutable($on, new \DateTimeZone('UTC')),
        );
    }

    private static function withoutValuation(string $on): NetWorth
    {
        return NetWorthCalculator::compute(
            [new NetWorthContribution(
                accountId: self::ACCOUNT,
                netWorthSign: 1,
                primaryGroupId: null,
                tagGroupIds: [],
                amount: null,
                quality: ValuationQuality::MISSING,
                ageDays: null,
                valuedOn: null,
                eligible: true,
            )],
            new \DateTimeImmutable($on, new \DateTimeZone('UTC')),
        );
    }
}
