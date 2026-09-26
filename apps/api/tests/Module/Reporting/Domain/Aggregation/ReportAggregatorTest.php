<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain\Aggregation;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\AggregateQuality;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\AggregationPopulationTooLarge;
use App\Module\Reporting\Domain\Aggregation\ExcludedReason;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Reporting\Domain\Aggregation\RateInputs;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;
use PHPUnit\Framework\TestCase;

final class ReportAggregatorTest extends TestCase
{
    public function testTwelveCompleteMonthsOfTheSameExpenseAreExact(): void
    {
        $aggregate = ReportAggregator::flow(self::months(2026, array_fill(0, 12, '100.10')), IncompleteMonths::EXCLUDE);

        self::assertSame('1201.20', $aggregate->total?->toString());
        self::assertSame(0, $aggregate->average?->compareTo(self::d('100.10')));
        self::assertSame(0, $aggregate->median?->compareTo(self::d('100.10')));
        self::assertSame('100.10', $aggregate->minimum?->value->toString());
        self::assertSame('2026-01', $aggregate->minimum->month->key());
        self::assertSame('100.10', $aggregate->maximum?->value->toString());
        self::assertSame('2026-01', $aggregate->maximum->month->key());
        self::assertSame(AggregateQuality::COMPLETE, $aggregate->quality);
        self::assertCount(12, $aggregate->countedMonths);
        self::assertSame([], $aggregate->excludedMonths);
        self::assertSame(AssetCode::fromString('EUR')->toString(), $aggregate->asset?->toString());
    }

    public function testAProvisionalMonthIsKeptInTheTotalButLeftOutOfTheAverageWhenExcluded(): void
    {
        $aggregate = ReportAggregator::flow(self::runningYear(), IncompleteMonths::EXCLUDE);

        self::assertSame('8400', $aggregate->total?->toString());
        self::assertSame(0, $aggregate->average?->compareTo(self::d('1000')));
        self::assertCount(8, $aggregate->countedMonths);
        self::assertCount(9, $aggregate->totalMonths);
        self::assertSame(AggregateQuality::PROVISIONAL, $aggregate->quality);
        self::assertSame(IncompleteMonths::EXCLUDE, $aggregate->incompleteMonths);
        self::assertContains([ExcludedReason::PROVISIONAL, '2026-09'], self::excluded($aggregate));
        self::assertContains([ExcludedReason::FUTURE, '2026-10'], self::excluded($aggregate));
    }

    public function testAProvisionalMonthCountsEverywhereWhenIncluded(): void
    {
        $aggregate = ReportAggregator::flow(self::runningYear(), IncompleteMonths::INCLUDE);

        self::assertSame('8400', $aggregate->total?->toString());
        self::assertSame('933.333333333333333333333333', $aggregate->average?->toString());
        self::assertCount(9, $aggregate->countedMonths);
        self::assertSame('400', $aggregate->minimum?->value->toString());
        self::assertSame('2026-09', $aggregate->minimum->month->key());
        self::assertNotContains([ExcludedReason::PROVISIONAL, '2026-09'], self::excluded($aggregate));
    }

    public function testARateIsTheRatioOfTheSumsAndNeverTheSumOfRates(): void
    {
        $aggregate = ReportAggregator::rate(
            new RateInputs(self::months(2026, ['500', '-200']), self::months(2026, ['2000', '0'])),
            [self::month(2026, 1, '0.25'), self::month(2026, 2, null)],
            IncompleteMonths::EXCLUDE,
        );

        self::assertSame(0, $aggregate->total?->compareTo(self::d('0.15')));
        self::assertSame(0, $aggregate->average?->compareTo(self::d('0.15')));
        self::assertSame(0, $aggregate->median?->compareTo(self::d('0.25')));
        self::assertSame('0.25', $aggregate->minimum?->value->toString());
        self::assertSame('0.25', $aggregate->maximum?->value->toString());
        self::assertContains([ExcludedReason::NULL_VALUE, '2026-02'], self::excluded($aggregate));
    }

    public function testARateWithoutAnyMonthlyRateHasNoMedianOrExtremes(): void
    {
        $aggregate = ReportAggregator::rate(
            new RateInputs(self::months(2026, ['1']), self::months(2026, ['2'])),
            [self::month(2026, 1, null)],
            IncompleteMonths::EXCLUDE,
        );

        self::assertNull($aggregate->median);
        self::assertSame(AggregateReason::EMPTY_POPULATION, $aggregate->medianReason);
        self::assertNull($aggregate->minimum);
        self::assertSame(AggregateReason::EMPTY_POPULATION, $aggregate->extremesReason);
    }

    public function testAZeroDenominatorSumGivesNoRate(): void
    {
        $aggregate = ReportAggregator::rate(
            new RateInputs(self::months(2026, ['0', '0']), self::months(2026, ['0', '0'])),
            [self::month(2026, 1, null), self::month(2026, 2, null)],
            IncompleteMonths::EXCLUDE,
        );

        self::assertNull($aggregate->total);
        self::assertSame(AggregateReason::ZERO_DENOMINATOR, $aggregate->totalReason);
        self::assertSame(AggregateReason::ZERO_DENOMINATOR, $aggregate->averageReason);
    }

    public function testANegativeDenominatorSumGivesNoRate(): void
    {
        $aggregate = ReportAggregator::rate(
            new RateInputs(self::months(2026, ['10']), self::months(2026, ['-100'])),
            [self::month(2026, 1, '-0.1')],
            IncompleteMonths::EXCLUDE,
        );

        self::assertNull($aggregate->total);
        self::assertSame(AggregateReason::NEGATIVE_DENOMINATOR, $aggregate->totalReason);
    }

    public function testARateWithAMissingNumeratorMonthIsNotComputable(): void
    {
        $aggregate = ReportAggregator::rate(
            new RateInputs([self::month(2026, 1, null)], self::months(2026, ['10'])),
            [self::month(2026, 1, null)],
            IncompleteMonths::EXCLUDE,
        );

        self::assertNull($aggregate->total);
        self::assertSame(AggregateReason::MISSING_MONTH_VALUE, $aggregate->totalReason);
        self::assertSame(AggregateQuality::PARTIAL, $aggregate->quality);
    }

    public function testRateInputsMustCoverTheSameMonths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReportAggregator::rate(
            new RateInputs(self::months(2026, ['1', '2']), self::months(2026, ['1'])),
            [],
            IncompleteMonths::EXCLUDE,
        );
    }

    public function testTheMedianOfAnEvenCountIsTheMeanOfTheTwoMiddleValues(): void
    {
        $aggregate = ReportAggregator::flow(self::months(2026, ['4', '1', '3', '2']), IncompleteMonths::EXCLUDE);

        self::assertSame(0, $aggregate->median?->compareTo(self::d('2.5')));
        self::assertSame('1', $aggregate->minimum?->value->toString());
        self::assertSame('4', $aggregate->maximum?->value->toString());
    }

    public function testAStockHasNoTotalButReportsItsPeriodEnd(): void
    {
        $aggregate = ReportAggregator::stock(self::months(2026, ['10000', '12000', '11000']), IncompleteMonths::EXCLUDE);

        self::assertNull($aggregate->total);
        self::assertSame(AggregateReason::NOT_ADDITIVE, $aggregate->totalReason);
        self::assertSame('11000', $aggregate->periodEnd?->value?->toString());
        self::assertSame('2026-03', $aggregate->periodEnd->month->key());
        self::assertSame(MonthState::COMPLETE, $aggregate->periodEnd->state);
        self::assertSame(0, $aggregate->average?->compareTo(self::d('11000')));
        self::assertSame(0, $aggregate->median?->compareTo(self::d('11000')));
    }

    public function testAMissingMonthValueMakesTheDependentFiguresNull(): void
    {
        $aggregate = ReportAggregator::flow(self::months(2026, ['1', '2', null]), IncompleteMonths::EXCLUDE);

        self::assertNull($aggregate->total);
        self::assertSame(AggregateReason::MISSING_MONTH_VALUE, $aggregate->totalReason);
        self::assertNull($aggregate->average);
        self::assertSame(AggregateReason::MISSING_MONTH_VALUE, $aggregate->averageReason);
        self::assertSame(AggregateReason::MISSING_MONTH_VALUE, $aggregate->medianReason);
        self::assertNull($aggregate->minimum);
        self::assertSame(AggregateReason::MISSING_MONTH_VALUE, $aggregate->extremesReason);
        self::assertSame(AggregateQuality::PARTIAL, $aggregate->quality);
    }

    public function testTwoPolicyVersionsNeverMix(): void
    {
        $months = [];
        foreach (range(1, 12) as $number) {
            $months[] = new MonthValue(new CalendarMonth(2026, $number), MonthState::COMPLETE, self::d('1'), AssetCode::fromString('EUR'), null, $number <= 4 ? 1 : 2);
        }
        $aggregate = ReportAggregator::flow($months, IncompleteMonths::EXCLUDE);

        self::assertNull($aggregate->total);
        self::assertSame(AggregateReason::MIXED_METRIC_POLICIES, $aggregate->totalReason);
        self::assertSame(AggregateReason::MIXED_METRIC_POLICIES, $aggregate->averageReason);
        self::assertSame(AggregateReason::MIXED_METRIC_POLICIES, $aggregate->medianReason);
        self::assertSame(AggregateReason::MIXED_METRIC_POLICIES, $aggregate->extremesReason);
        self::assertNull($aggregate->policyVersion);
    }

    public function testASinglePolicyVersionIsReported(): void
    {
        $aggregate = ReportAggregator::flow(
            [new MonthValue(new CalendarMonth(2026, 1), MonthState::COMPLETE, self::d('1'), null, null, 3), self::month(2026, 2, '1')],
            IncompleteMonths::EXCLUDE,
        );

        self::assertSame(3, $aggregate->policyVersion);
        self::assertSame('2', $aggregate->total?->toString());
    }

    public function testTwoAssetsNeverMix(): void
    {
        $aggregate = ReportAggregator::flow([
            new MonthValue(new CalendarMonth(2026, 1), MonthState::COMPLETE, self::d('1'), AssetCode::fromString('EUR')),
            new MonthValue(new CalendarMonth(2026, 2), MonthState::COMPLETE, self::d('1'), AssetCode::fromString('USD')),
        ], IncompleteMonths::EXCLUDE);

        self::assertNull($aggregate->total);
        self::assertSame(AggregateReason::MIXED_ASSETS, $aggregate->totalReason);
        self::assertNull($aggregate->asset);
    }

    public function testTwentyFourDecimalsSumExactly(): void
    {
        $aggregate = ReportAggregator::flow(self::months(2026, array_fill(0, 12, '0.000000000000000000000001')), IncompleteMonths::EXCLUDE);

        self::assertSame('0.000000000000000000000012', $aggregate->total?->toString());
    }

    public function testVeryLargeAmountsSumExactly(): void
    {
        $aggregate = ReportAggregator::flow(self::months(2026, ['-99999999999999999999999999.999999999999999999999999', '99999999999999999999999999.999999999999999999999998']), IncompleteMonths::EXCLUDE);

        self::assertSame('-0.000000000000000000000001', $aggregate->total?->toString());
    }

    public function testFutureAndNoDataMonthsNeverCountAndNeverReadAsZero(): void
    {
        $aggregate = ReportAggregator::flow([
            new MonthValue(new CalendarMonth(2026, 1), MonthState::NO_DATA, null),
            new MonthValue(new CalendarMonth(2026, 2), MonthState::COMPLETE, self::d('10')),
            new MonthValue(new CalendarMonth(2026, 3), MonthState::FUTURE, null),
        ], IncompleteMonths::EXCLUDE);

        self::assertSame('10', $aggregate->total?->toString());
        self::assertSame(0, $aggregate->average?->compareTo(self::d('10')));
        self::assertSame(AggregateQuality::PARTIAL, $aggregate->quality);
        self::assertContains([ExcludedReason::NO_DATA, '2026-01'], self::excluded($aggregate));
        self::assertContains([ExcludedReason::FUTURE, '2026-03'], self::excluded($aggregate));
    }

    public function testAnEmptyPopulationHasNoFigureAndEmptyQuality(): void
    {
        $aggregate = ReportAggregator::flow([new MonthValue(new CalendarMonth(2026, 1), MonthState::FUTURE, null)], IncompleteMonths::EXCLUDE);

        self::assertNull($aggregate->total);
        self::assertSame(AggregateReason::EMPTY_POPULATION, $aggregate->totalReason);
        self::assertSame(AggregateReason::EMPTY_POPULATION, $aggregate->averageReason);
        self::assertSame(AggregateQuality::EMPTY, $aggregate->quality);
        self::assertSame(AggregateQuality::EMPTY, ReportAggregator::flow([], IncompleteMonths::INCLUDE)->quality);
    }

    public function testOnlyAProvisionalMonthLeavesNoStatisticsWhenExcluded(): void
    {
        $aggregate = ReportAggregator::flow([new MonthValue(new CalendarMonth(2026, 9), MonthState::PROVISIONAL, self::d('5'))], IncompleteMonths::EXCLUDE);

        self::assertSame('5', $aggregate->total?->toString());
        self::assertNull($aggregate->average);
        self::assertSame(AggregateReason::EMPTY_POPULATION, $aggregate->averageReason);
        self::assertSame(AggregateReason::EMPTY_POPULATION, $aggregate->extremesReason);
    }

    public function testATieOnTheExtremeKeepsTheEarliestMonth(): void
    {
        $aggregate = ReportAggregator::flow(self::months(2026, ['5', '1', '1', '5']), IncompleteMonths::EXCLUDE);

        self::assertSame('2026-02', $aggregate->minimum?->month->key());
        self::assertSame('2026-01', $aggregate->maximum?->month->key());
    }

    public function testMonthStatesFollowTheFirstDataMonthAndTheWorkspaceToday(): void
    {
        $states = ReportAggregator::monthStates(
            new CalendarMonth(2026, 1),
            new CalendarMonth(2026, 12),
            new CalendarMonth(2026, 4),
            new \DateTimeImmutable('2026-09-15'),
        );

        self::assertSame(range(1, 12), array_map(static fn (string $key): int => (int) substr($key, 5), array_keys($states)));
        self::assertSame('2026-01', array_key_first($states));
        foreach (['2026-01', '2026-02', '2026-03'] as $key) {
            self::assertSame(MonthState::NO_DATA, $states[$key]);
        }
        foreach (['2026-04', '2026-08'] as $key) {
            self::assertSame(MonthState::COMPLETE, $states[$key]);
        }
        self::assertSame(MonthState::PROVISIONAL, $states['2026-09']);
        foreach (['2026-10', '2026-12'] as $key) {
            self::assertSame(MonthState::FUTURE, $states[$key]);
        }

        $months = [];
        foreach ($states as $key => $state) {
            $months[] = new MonthValue(CalendarMonth::fromString($key), $state, MonthState::NO_DATA === $state || MonthState::FUTURE === $state ? null : self::d('1'));
        }
        self::assertSame(AggregateQuality::PARTIAL, ReportAggregator::flow($months, IncompleteMonths::EXCLUDE)->quality);
    }

    public function testWithoutAnyDataEveryPastMonthIsNoDataAndTheRunningMonthIsProvisional(): void
    {
        $states = ReportAggregator::monthStates(new CalendarMonth(2026, 8), new CalendarMonth(2026, 10), null, new \DateTimeImmutable('2026-09-15'));

        self::assertSame([MonthState::NO_DATA, MonthState::PROVISIONAL, MonthState::FUTURE], array_values($states));
    }

    public function testTheRangeOfMonthStatesIsBounded(): void
    {
        $this->expectException(AggregationPopulationTooLarge::class);

        ReportAggregator::monthStates(new CalendarMonth(2000, 1), new CalendarMonth(2020, 1), null, new \DateTimeImmutable('2026-09-15'));
    }

    public function testMoreThanTwoHundredFortyMonthsAreRefused(): void
    {
        $months = [];
        for ($i = 0; $i < 241; ++$i) {
            $months[] = new MonthValue(new CalendarMonth(2000 + intdiv($i, 12), $i % 12 + 1), MonthState::COMPLETE, self::d('1'));
        }

        $this->expectException(AggregationPopulationTooLarge::class);

        ReportAggregator::flow($months, IncompleteMonths::EXCLUDE);
    }

    public function testExactlyTwoHundredFortyMonthsAreAccepted(): void
    {
        $months = [];
        for ($i = 0; $i < 240; ++$i) {
            $months[] = new MonthValue(new CalendarMonth(2000 + intdiv($i, 12), $i % 12 + 1), MonthState::COMPLETE, self::d('1'));
        }

        self::assertSame('240', ReportAggregator::flow($months, IncompleteMonths::EXCLUDE)->total?->toString());
    }

    public function testAveragesOfTwoPeriodsAreComparedByDifference(): void
    {
        $current = ReportAggregator::flow(self::months(2026, ['10', '20']), IncompleteMonths::EXCLUDE);
        $previous = ReportAggregator::flow(self::months(2025, ['4', '5']), IncompleteMonths::EXCLUDE);

        self::assertSame(0, ReportAggregator::compareAverages($current, $previous)?->compareTo(self::d('10.5')));
        self::assertNull(ReportAggregator::compareAveragesReason($current, $previous));
    }

    public function testAveragesUnderDifferentPoliciesAreNotComparable(): void
    {
        $current = ReportAggregator::flow([new MonthValue(new CalendarMonth(2026, 1), MonthState::COMPLETE, self::d('1'), null, null, 2)], IncompleteMonths::EXCLUDE);
        $previous = ReportAggregator::flow([new MonthValue(new CalendarMonth(2025, 1), MonthState::COMPLETE, self::d('1'), null, null, 1)], IncompleteMonths::EXCLUDE);

        self::assertNull(ReportAggregator::compareAverages($current, $previous));
        self::assertSame(AggregateReason::POLICY_MISMATCH, ReportAggregator::compareAveragesReason($current, $previous));
    }

    public function testComparisonIsNullWhenAnAverageIsNull(): void
    {
        $current = ReportAggregator::flow(self::months(2026, ['1']), IncompleteMonths::EXCLUDE);
        $previous = ReportAggregator::flow(self::months(2025, [null]), IncompleteMonths::EXCLUDE);

        self::assertNull(ReportAggregator::compareAverages($current, $previous));
        self::assertNull(ReportAggregator::compareAveragesReason($current, $previous));
    }

    public function testAveragesInDifferentAssetsAreNotComparable(): void
    {
        $eur = ReportAggregator::flow([self::month(2026, 1, '10')], IncompleteMonths::EXCLUDE);
        $usd = ReportAggregator::flow([new MonthValue(new CalendarMonth(2025, 1), MonthState::COMPLETE, self::d('4'), AssetCode::fromString('USD'))], IncompleteMonths::EXCLUDE);

        self::assertNull(ReportAggregator::compareAverages($eur, $usd));
        self::assertSame(AggregateReason::MIXED_ASSETS, ReportAggregator::compareAveragesReason($eur, $usd));
    }

    public function testAveragesOfDifferentColumnKindsAreNotComparable(): void
    {
        $flow = ReportAggregator::flow(self::months(2026, ['10']), IncompleteMonths::EXCLUDE);
        $stock = ReportAggregator::stock(self::months(2025, ['4']), IncompleteMonths::EXCLUDE);

        self::assertNull(ReportAggregator::compareAverages($flow, $stock));
    }

    public function testARepeatedMonthIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReportAggregator::flow([self::month(2026, 1, '1'), self::month(2026, 1, '2')], IncompleteMonths::EXCLUDE);
    }

    public function testARepeatedDenominatorMonthIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReportAggregator::rate(
            new RateInputs([self::month(2026, 1, '1'), self::month(2026, 2, '1')], [self::month(2026, 1, '1'), self::month(2026, 1, '2')]),
            [self::month(2026, 1, '1'), self::month(2026, 2, '1')],
            IncompleteMonths::EXCLUDE,
        );
    }

    public function testRateInputsMustAgreeOnTheStateOfEachMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReportAggregator::rate(
            new RateInputs([self::month(2026, 1, '1')], [self::month(2026, 1, '2', MonthState::PROVISIONAL)]),
            [self::month(2026, 1, '0.5')],
            IncompleteMonths::EXCLUDE,
        );
    }

    public function testRateInputsUnderDifferentPolicyVersionsNeverMix(): void
    {
        $aggregate = ReportAggregator::rate(
            new RateInputs(
                [new MonthValue(new CalendarMonth(2026, 1), MonthState::COMPLETE, self::d('1'), null, null, 1)],
                [new MonthValue(new CalendarMonth(2026, 1), MonthState::COMPLETE, self::d('2'), null, null, 2)],
            ),
            [self::month(2026, 1, '0.5')],
            IncompleteMonths::EXCLUDE,
        );

        self::assertNull($aggregate->total);
        self::assertSame(AggregateReason::MIXED_METRIC_POLICIES, $aggregate->totalReason);
    }

    public function testARateKeepsTheProvisionalMonthInTheTotalOnly(): void
    {
        $aggregate = ReportAggregator::rate(
            new RateInputs(
                [self::month(2026, 8, '100'), self::month(2026, 9, '50', MonthState::PROVISIONAL)],
                [self::month(2026, 8, '400'), self::month(2026, 9, '100', MonthState::PROVISIONAL)],
            ),
            [self::month(2026, 8, '0.25'), self::month(2026, 9, '0.5', MonthState::PROVISIONAL)],
            IncompleteMonths::EXCLUDE,
        );

        self::assertSame(0, $aggregate->total?->compareTo(self::d('0.3')));
        self::assertSame(0, $aggregate->average?->compareTo(self::d('0.25')));
        self::assertCount(2, $aggregate->totalMonths);
        self::assertCount(1, $aggregate->countedMonths);
        self::assertSame('0.25', $aggregate->maximum?->value->toString());
        self::assertSame(AggregateQuality::PROVISIONAL, $aggregate->quality);
    }

    public function testAStockWhoseLatestMonthIsNullReportsANullPeriodEndAndPartialQuality(): void
    {
        $aggregate = ReportAggregator::stock(self::months(2026, ['10', '12', null]), IncompleteMonths::EXCLUDE);

        self::assertNotNull($aggregate->periodEnd);
        self::assertNull($aggregate->periodEnd->value);
        self::assertSame('2026-03', $aggregate->periodEnd->month->key());
        self::assertSame(AggregateQuality::PARTIAL, $aggregate->quality);
    }

    public function testAFirstDataMonthInTheFutureLeavesNoCompleteMonth(): void
    {
        $states = ReportAggregator::monthStates(new CalendarMonth(2026, 8), new CalendarMonth(2026, 12), new CalendarMonth(2026, 11), new \DateTimeImmutable('2026-09-15'));

        self::assertSame(
            [MonthState::NO_DATA, MonthState::NO_DATA, MonthState::FUTURE, MonthState::FUTURE, MonthState::FUTURE],
            array_values($states),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        foreach (range(1, 40) as $seed) {
            yield 'seed '.$seed => [$seed];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('seeds')]
    public function testTheTotalMatchesAnIndependentFoldAndTheOrderOfStatisticsHolds(int $seed): void
    {
        mt_srand($seed);
        $count = mt_rand(1, 24);
        $literals = [];
        $fold = \Brick\Math\BigDecimal::zero();
        for ($i = 0; $i < $count; ++$i) {
            $scale = mt_rand(0, 8);
            $integer = (string) mt_rand(0, 999999);
            $fraction = 0 === $scale ? '' : '.'.str_pad((string) mt_rand(0, (int) (10 ** $scale) - 1), $scale, '0', STR_PAD_LEFT);
            $literal = (mt_rand(0, 1) && '0' !== $integer ? '-' : '').$integer.$fraction;
            $literals[] = $literal;
            $fold = $fold->plus(\Brick\Math\BigDecimal::of($literal));
        }

        $aggregate = ReportAggregator::flow(self::months(2026, $literals), IncompleteMonths::EXCLUDE);

        self::assertNotNull($aggregate->total);
        self::assertTrue($fold->isEqualTo(\Brick\Math\BigDecimal::of($aggregate->total->toString())));
        self::assertNotNull($aggregate->minimum);
        self::assertNotNull($aggregate->maximum);
        self::assertNotNull($aggregate->median);
        self::assertLessThanOrEqual(0, $aggregate->minimum->value->compareTo($aggregate->median));
        self::assertLessThanOrEqual(0, $aggregate->median->compareTo($aggregate->maximum->value));
    }

    /**
     * @param list<string|null> $values
     *
     * @return list<MonthValue>
     */
    private static function months(int $year, array $values): array
    {
        $months = [];
        foreach ($values as $index => $value) {
            $months[] = self::month($year + intdiv($index, 12), $index % 12 + 1, $value);
        }

        return $months;
    }

    private static function month(int $year, int $month, ?string $value, MonthState $state = MonthState::COMPLETE): MonthValue
    {
        return new MonthValue(new CalendarMonth($year, $month), $state, null === $value ? null : self::d($value), AssetCode::fromString('EUR'));
    }

    /** @return list<MonthValue> January to August complete at 1000, September provisional at 400, rest future */
    private static function runningYear(): array
    {
        $months = [];
        foreach (range(1, 12) as $number) {
            $months[] = match (true) {
                $number <= 8 => self::month(2026, $number, '1000'),
                9 === $number => self::month(2026, $number, '400', MonthState::PROVISIONAL),
                default => new MonthValue(new CalendarMonth(2026, $number), MonthState::FUTURE, null),
            };
        }

        return $months;
    }

    /** @return list<array{ExcludedReason, string}> */
    private static function excluded(Aggregate $aggregate): array
    {
        return array_map(static fn ($excluded): array => [$excluded->reason, $excluded->month->key()], $aggregate->excludedMonths);
    }

    private static function d(string $literal): DecimalValue
    {
        return DecimalValue::fromString($literal);
    }
}
