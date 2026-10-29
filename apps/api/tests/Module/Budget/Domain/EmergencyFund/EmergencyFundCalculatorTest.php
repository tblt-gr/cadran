<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain\EmergencyFund;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundCalculator;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundCoverage;
use App\Module\Budget\Domain\EmergencyFund\FixedRequirement;
use App\Module\Budget\Domain\EmergencyFund\LiquidAccount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use PHPUnit\Framework\TestCase;

/**
 * Reference figures, computed by hand:
 * liquid 3000.00 + 9600.00 = 12600.00; average 2100.00; covered 12600 / 2100 = 6;
 * required 6 x 2100 = 12600; gap 12600 - 12600 = 0.
 */
final class EmergencyFundCalculatorTest extends TestCase
{
    public function testReferenceOneTwoAccountsCoverExactlyTheTarget(): void
    {
        $coverage = self::compute([self::account('a', '3000.00'), self::account('b', '9600.00')], self::year('2100.00'), '6');

        self::assertDecimal('12600', $coverage->liquidAssets);
        self::assertSame('EUR', $coverage->liquidAssetCode);
        self::assertDecimal('2100', $coverage->average);
        self::assertSame('EUR', $coverage->referenceAssetCode);
        self::assertSame(12, $coverage->usedMonths);
        self::assertSame(12, $coverage->expectedMonths);
        self::assertDecimal('6', $coverage->coveredMonths);
        self::assertSame('6.0', $coverage->coveredMonthsDisplay);
        self::assertDecimal('12600', $coverage->requiredAmount);
        self::assertDecimal('0', $coverage->gap);
        self::assertNull($coverage->reason);
        self::assertSame('COMPLETE', $coverage->quality);
    }

    public function testReferenceTwoAShortfallIsANegativeGapAndCoverageRoundsHalfUpForDisplay(): void
    {
        $coverage = self::compute([self::account('a', '10260.00')], self::year('2100.00'), '6');

        self::assertSame('4.885714285714285714285714', $coverage->coveredMonths);
        self::assertSame('4.9', $coverage->coveredMonthsDisplay);
        self::assertDecimal('12600', $coverage->requiredAmount);
        self::assertDecimal('-2340', $coverage->gap);
    }

    public function testReferenceThreeMonthsWithoutDataAreExcludedAndTheAverageDividesByUsedMonths(): void
    {
        $months = [];
        for ($index = 1; $index <= 12; ++$index) {
            $months[] = $index <= 6
                ? new MonthValue(new CalendarMonth(2025, $index), MonthState::NO_DATA, null)
                : self::month(2025, $index, '2100.00');
        }

        $coverage = self::compute([self::account('a', '12600.00')], $months, '6');

        self::assertSame(6, $coverage->usedMonths);
        self::assertSame(12, $coverage->expectedMonths);
        self::assertSame(['2025-01', '2025-02', '2025-03', '2025-04', '2025-05', '2025-06'], array_map(static fn ($excluded): string => $excluded->month->key(), $coverage->excludedMonths));
        self::assertSame(['NO_DATA'], array_values(array_unique(array_map(static fn ($excluded): string => $excluded->reason->value, $coverage->excludedMonths))));
        self::assertDecimal('2100', $coverage->average);
        self::assertDecimal('6', $coverage->coveredMonths);
        self::assertSame('PARTIAL', $coverage->quality);
    }

    public function testReferenceFourZeroEssentialExpensesLeaveCoverageNullNeverInfiniteOrZero(): void
    {
        $coverage = self::compute([self::account('a', '5000.00')], self::year('0'), '6');

        self::assertDecimal('0', $coverage->average);
        self::assertNull($coverage->averageReason);
        self::assertNull($coverage->coveredMonths);
        self::assertNull($coverage->coveredMonthsDisplay);
        self::assertSame('ZERO_ESSENTIAL_EXPENSES', $coverage->reason);
        self::assertDecimal('0', $coverage->requiredAmount);
        self::assertDecimal('5000', $coverage->gap);
        self::assertSame('NOT_CALCULABLE', $coverage->quality);
    }

    public function testReferenceFiveAStaleValuationIsCountedAndFlagged(): void
    {
        $coverage = self::compute([self::account('a', '6000.00', 45), self::account('b', '6600.00')], self::year('2100.00'), '6');

        self::assertDecimal('12600', $coverage->liquidAssets);
        self::assertTrue($coverage->accounts[0]->isStale());
        self::assertFalse($coverage->accounts[1]->isStale());
        self::assertDecimal('6', $coverage->coveredMonths);
        self::assertSame('STALE_VALUATION', $coverage->reason);
        self::assertSame('PARTIAL', $coverage->quality);
    }

    public function testReferenceFiveAnAccountWithoutValuationMakesLiquidAssetsUnknown(): void
    {
        $missing = new LiquidAccount('b', 'Livret B', null, null, null, null);

        $coverage = self::compute([self::account('a', '3000.00'), $missing], self::year('2100.00'), '6');

        self::assertNull($coverage->liquidAssets);
        self::assertSame('MISSING_VALUATION', $coverage->liquidReason);
        self::assertNull($coverage->accounts[1]->value);
        self::assertNull($coverage->coveredMonths);
        self::assertNull($coverage->gap);
        self::assertDecimal('12600', $coverage->requiredAmount);
        self::assertSame('MISSING_VALUATION', $coverage->reason);
    }

    public function testTheStaleThresholdIsStrictlyMoreThanThirtyOneDays(): void
    {
        self::assertFalse(self::account('a', '1.00', 31)->isStale());
        self::assertTrue(self::account('a', '1.00', 32)->isStale());
    }

    public function testNoUsableReferenceMonthLeavesTheAverageAndEveryDerivedFigureNull(): void
    {
        $months = array_map(static fn (int $index): MonthValue => new MonthValue(new CalendarMonth(2025, $index), MonthState::NO_DATA, null), range(1, 12));

        $coverage = self::compute([self::account('a', '3000.00')], $months, '6');

        self::assertNull($coverage->average);
        self::assertSame('NO_REFERENCE_MONTH', $coverage->averageReason);
        self::assertSame(0, $coverage->usedMonths);
        self::assertNull($coverage->requiredAmount);
        self::assertNull($coverage->gap);
        self::assertNull($coverage->coveredMonths);
        self::assertSame('NO_REFERENCE_MONTH', $coverage->reason);
    }

    public function testAMonthWithAnUnknownValueMakesTheAverageUnknownRatherThanSkippingIt(): void
    {
        $months = array_map(static fn (MonthValue $month): MonthValue => 4 === $month->month->month ? new MonthValue($month->month, MonthState::COMPLETE, null, null, 'MIXED_ASSETS') : $month, self::year('2100.00'));

        $coverage = self::compute([self::account('a', '3000.00')], $months, '6');

        self::assertNull($coverage->average);
        self::assertSame('MISSING_MONTH_VALUE', $coverage->averageReason);
        self::assertNull($coverage->coveredMonths);
    }

    public function testANegativeEligibleBalanceLowersLiquidAssets(): void
    {
        $coverage = self::compute([self::account('a', '12900.00'), self::account('b', '-300.00')], self::year('2100.00'), '6');

        self::assertDecimal('12600', $coverage->liquidAssets);
        self::assertDecimal('6', $coverage->coveredMonths);
    }

    public function testAccountsInDifferentAssetsAreNotAddedUp(): void
    {
        $usd = new LiquidAccount('b', 'Dollar', DecimalValue::fromString('100.00'), AssetCode::fromString('USD'), new \DateTimeImmutable('2026-09-29'), 0);

        $coverage = self::compute([self::account('a', '3000.00'), $usd], self::year('2100.00'), '6');

        self::assertNull($coverage->liquidAssets);
        self::assertSame('MIXED_ASSETS', $coverage->liquidReason);
        self::assertSame('MIXED_ASSETS', $coverage->reason);
        self::assertNull($coverage->coveredMonths);
    }

    public function testLiquidAssetsInAnotherAssetThanTheExpensesAreNotDivided(): void
    {
        $usd = new LiquidAccount('b', 'Dollar', DecimalValue::fromString('100.00'), AssetCode::fromString('USD'), new \DateTimeImmutable('2026-09-29'), 0);

        $coverage = self::compute([$usd], self::year('2100.00'), '6');

        self::assertDecimal('100', $coverage->liquidAssets);
        self::assertSame('USD', $coverage->liquidAssetCode);
        self::assertSame('EUR', $coverage->referenceAssetCode);
        self::assertNull($coverage->coveredMonths);
        self::assertDecimal('12600', $coverage->requiredAmount);
        self::assertNull($coverage->gap);
        self::assertSame('MIXED_ASSETS', $coverage->reason);
    }

    public function testNoEligibleAccountIsNotAZeroFund(): void
    {
        $coverage = self::compute([], self::year('2100.00'), '6');

        self::assertNull($coverage->liquidAssets);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', $coverage->liquidReason);
        self::assertNull($coverage->coveredMonths);
        self::assertDecimal('12600', $coverage->requiredAmount);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', $coverage->reason);
    }

    public function testATargetOfZeroMonthsRequiresNothing(): void
    {
        $coverage = self::compute([self::account('a', '100.00')], self::year('2100.00'), '0');

        self::assertDecimal('0', $coverage->requiredAmount);
        self::assertDecimal('100', $coverage->gap);
    }

    public function testAFixedAmountReplacesMonthsTimesAverageButStillReportsTheCoveredMonths(): void
    {
        $coverage = EmergencyFundCalculator::compute([self::account('a', '10260.00')], self::year('2100.00'), DecimalValue::fromString('6'), 12, new FixedRequirement('15000.00', 'EUR', null));

        self::assertDecimal('15000', $coverage->requiredAmount);
        self::assertDecimal('-4740', $coverage->gap);
        self::assertSame('4.9', $coverage->coveredMonthsDisplay);
        self::assertNull($coverage->reason);
        self::assertSame('COMPLETE', $coverage->quality);
    }

    public function testAFixedAmountNeedsNoExpenseAverageToHaveAGap(): void
    {
        $coverage = EmergencyFundCalculator::compute([self::account('a', '10000.00')], [], DecimalValue::fromString('6'), 12, new FixedRequirement('8000.00', 'EUR', null));

        self::assertDecimal('2000', $coverage->gap);
        self::assertNull($coverage->coveredMonths);
        self::assertNull($coverage->reason);
        self::assertSame('COMPLETE', $coverage->quality);
    }

    public function testAnUnknownFixedRequirementIsNullWithItsReasonNeverZero(): void
    {
        $coverage = EmergencyFundCalculator::compute([self::account('a', '10000.00')], self::year('2100.00'), DecimalValue::fromString('6'), 12, new FixedRequirement(null, null, 'NO_REFERENCE_INCOME'));

        self::assertNull($coverage->requiredAmount);
        self::assertNull($coverage->gap);
        self::assertSame('NO_REFERENCE_INCOME', $coverage->reason);
        self::assertSame('NOT_CALCULABLE', $coverage->quality);
    }

    public function testAFixedAmountInAnotherAssetThanTheLiquidAssetsHasNoGap(): void
    {
        $coverage = EmergencyFundCalculator::compute([self::account('a', '10000.00')], self::year('2100.00'), DecimalValue::fromString('6'), 12, new FixedRequirement('8000.00', 'USD', null));

        self::assertNull($coverage->gap);
        self::assertSame('MIXED_ASSETS', $coverage->reason);
    }

    public function testVeryLargeDecimalsStayExactBeyondTheStorageWidth(): void
    {
        $large = '999999999999999999999999.999999999999999999999999';
        $coverage = self::compute([self::account('a', $large), self::account('b', $large)], self::year('0.000000000000000000000001'), '60');

        self::assertSame('1999999999999999999999999.999999999999999999999998', $coverage->liquidAssets);
        self::assertDecimal('0.00000000000000000000006', $coverage->requiredAmount);
        // liquid / 10^-24 shifts the 24 fraction digits into the integer part: 49 digits in all.
        self::assertSame('1999999999999999999999999999999999999999999999998.000000000000000000000000', $coverage->coveredMonths);
        self::assertSame('1999999999999999999999999999999999999999999999998.0', $coverage->coveredMonthsDisplay);
    }

    /**
     * @param list<LiquidAccount> $accounts
     * @param list<MonthValue>    $months
     */
    private static function compute(array $accounts, array $months, string $target): EmergencyFundCoverage
    {
        return EmergencyFundCalculator::compute($accounts, $months, DecimalValue::fromString($target), 12);
    }

    private static function account(string $id, string $value, int $ageDays = 0): LiquidAccount
    {
        return new LiquidAccount($id, 'Livret '.$id, DecimalValue::fromString($value), AssetCode::fromString('EUR'), (new \DateTimeImmutable('2026-09-29'))->modify(sprintf('-%d days', $ageDays)), $ageDays);
    }

    /** @return list<MonthValue> */
    private static function year(string $value): array
    {
        return array_map(static fn (int $index): MonthValue => self::month(2025, $index, $value), range(1, 12));
    }

    private static function month(int $year, int $month, string $value): MonthValue
    {
        return new MonthValue(new CalendarMonth($year, $month), MonthState::COMPLETE, DecimalValue::fromString($value), AssetCode::fromString('EUR'), null, 1);
    }

    private static function assertDecimal(string $expected, ?string $actual): void
    {
        self::assertNotNull($actual);
        self::assertSame(0, DecimalValue::fromString($expected)->compareTo(DecimalValue::fromString($actual)), sprintf('%s is not %s', $actual, $expected));
    }
}
