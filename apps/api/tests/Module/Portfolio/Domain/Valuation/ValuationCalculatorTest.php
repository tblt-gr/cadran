<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Portfolio\Domain\Valuation\HeldPosition;
use App\Module\Portfolio\Domain\Valuation\PriceEntry;
use App\Module\Portfolio\Domain\Valuation\PriceQuality;
use App\Module\Portfolio\Domain\Valuation\ValuationCalculator;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValuationCalculatorTest extends TestCase
{
    private const string ETF = '00000000-0000-7000-8000-000000000b01';
    private const string STOCK = '00000000-0000-7000-8000-000000000b02';

    #[Test]
    public function aTotalValuationDerivesSecuritiesFromTheTotalMinusDerivedCashAndKeepsLinesUnpriced(): void
    {
        $figures = ValuationCalculator::total(
            derivedCash: self::dec('1193.28'),
            observedCash: null,
            totalValue: self::dec('3664.70'),
            positions: $this->held(),
        );

        self::assertSame('1193.28', $figures->cash->toString());
        self::assertFalse($figures->cashObserved);
        self::assertSame('2471.42', $figures->securitiesValue?->toString());
        self::assertSame('3664.70', $figures->totalValue?->toString());
        self::assertSame(ValuationCompleteness::COMPLETE, $figures->completeness);
        self::assertCount(2, $figures->lines);
        foreach ($figures->lines as $line) {
            self::assertNull($line->unitPrice);
            self::assertNull($line->marketValue);
            self::assertSame(PriceQuality::NOT_DETAILED, $line->priceQuality);
        }
        self::assertSame('10', $figures->lines[0]->quantity->toString());
        self::assertSame('2312.00', $figures->lines[0]->averageCostBasis?->toString());
    }

    #[Test]
    public function aTotalLowerThanTheDerivedCashGivesANegativeSecuritiesValue(): void
    {
        $figures = ValuationCalculator::total(self::dec('1193.28'), null, self::dec('1000.00'), $this->held());

        self::assertSame('-193.28', $figures->securitiesValue?->toString());
    }

    #[Test]
    public function anObservedCashReplacesTheDerivedCashInATotalValuation(): void
    {
        $figures = ValuationCalculator::total(self::dec('1193.28'), self::dec('1200.00'), self::dec('3664.70'), $this->held());

        self::assertTrue($figures->cashObserved);
        self::assertSame('1200.00', $figures->cash->toString());
        self::assertSame('2464.70', $figures->securitiesValue?->toString());
    }

    #[Test]
    public function aPositionsValuationMultipliesExactlyAndSumsToTheTotal(): void
    {
        $figures = ValuationCalculator::positions(
            valuedOn: self::day('2026-03-31'),
            displayPrecision: 2,
            derivedCash: self::dec('1193.28'),
            observedCash: null,
            positions: $this->held(),
            prices: [
                self::ETF => new PriceEntry(self::dec('231.4521'), self::day('2026-03-30')),
                self::STOCK => new PriceEntry(self::dec('52.30'), self::day('2026-03-31')),
            ],
        );

        self::assertSame('2314.521', $figures->lines[0]->marketValue?->toString());
        self::assertSame('156.90', $figures->lines[1]->marketValue?->toString());
        self::assertSame('2471.421', $figures->securitiesValue?->toString());
        self::assertSame('3664.701', $figures->totalValue?->toString());
        self::assertSame(ValuationCompleteness::COMPLETE, $figures->completeness);
        self::assertSame(PriceQuality::CURRENT, $figures->lines[0]->priceQuality);
    }

    #[Test]
    public function aMissingPriceMakesTheValuationPartialWithNullTotalsNeverZero(): void
    {
        $figures = ValuationCalculator::positions(
            self::day('2026-03-31'), 2, self::dec('1193.28'), null, $this->held(),
            [self::ETF => new PriceEntry(null, null), self::STOCK => new PriceEntry(self::dec('52.30'), self::day('2026-03-31'))],
        );

        self::assertNull($figures->lines[0]->marketValue);
        self::assertSame(PriceQuality::MISSING, $figures->lines[0]->priceQuality);
        self::assertNull($figures->securitiesValue);
        self::assertNull($figures->totalValue);
        self::assertSame(ValuationCompleteness::PARTIAL, $figures->completeness);
        self::assertSame('156.90', $figures->lines[1]->marketValue?->toString());
    }

    #[Test]
    public function aPriceFiveDaysOldIsCurrentAndSixDaysOldIsStale(): void
    {
        $figures = ValuationCalculator::positions(
            self::day('2026-03-31'), 2, self::dec('0'), null, $this->held(),
            [
                self::ETF => new PriceEntry(self::dec('1'), self::day('2026-03-26')),
                self::STOCK => new PriceEntry(self::dec('1'), self::day('2026-03-25')),
            ],
        );

        self::assertSame(PriceQuality::CURRENT, $figures->lines[0]->priceQuality);
        self::assertSame(PriceQuality::STALE, $figures->lines[1]->priceQuality);
    }

    #[Test]
    public function aWholeValuationWithoutHoldingsIsCashOnlyAndComplete(): void
    {
        $figures = ValuationCalculator::positions(self::day('2026-03-31'), 2, self::dec('12.50'), null, [], []);

        self::assertSame('0', $figures->securitiesValue?->toString());
        self::assertSame('12.50', $figures->totalValue?->toString());
    }

    #[Test]
    public function aQuantityAndAPriceAtTwelveDecimalsMultiplyExactlyToTwentyFour(): void
    {
        $held = [new HeldPosition(self::ETF, self::dec('1.123456789012'), null)];
        $figures = ValuationCalculator::positions(
            self::day('2026-03-31'), 2, self::dec('0'), null, $held,
            [self::ETF => new PriceEntry(self::dec('12.123456789012'), self::day('2026-03-31'))],
        );

        self::assertSame('13.620179835909153483936144', $figures->lines[0]->marketValue?->toString());
        self::assertSame(24, $figures->lines[0]->marketValue->scale());
    }

    #[Test]
    public function aSumBeyondTheStorableWidthFailsInsteadOfWrapping(): void
    {
        $this->expectException(PrecisionExceeded::class);
        $held = [new HeldPosition(self::ETF, self::dec('99999999999999999999999999'), null)];
        ValuationCalculator::positions(
            self::day('2026-03-31'), 2, self::dec('0'), null, $held,
            [self::ETF => new PriceEntry(self::dec('9999'), self::day('2026-03-31'))],
        );
    }

    /** @return list<HeldPosition> */
    private function held(): array
    {
        return [
            new HeldPosition(self::ETF, self::dec('10'), self::dec('2312.00')),
            new HeldPosition(self::STOCK, self::dec('3'), self::dec('156.90')),
        ];
    }

    private static function dec(string $literal): DecimalValue
    {
        return DecimalValue::fromString($literal);
    }

    private static function day(string $literal): \DateTimeImmutable
    {
        return new \DateTimeImmutable($literal, new \DateTimeZone('UTC'));
    }
}
