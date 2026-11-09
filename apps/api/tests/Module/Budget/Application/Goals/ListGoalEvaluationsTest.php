<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Application\ReadAccountReference;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalEvaluationGranularity;
use App\Module\Budget\Application\Goals\GoalEvaluationView;
use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Budget\Application\Goals\ListGoalEvaluations;
use App\Module\Budget\Application\Goals\ReadGoalStatus;
use App\Module\Budget\Application\Goals\ResolveGoalScope;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualColumn;
use App\Module\Reporting\Application\AnnualColumnSeries;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Transactions\Application\MonthlyTransferPairReader;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ListGoalEvaluationsTest extends TestCase
{
    private const string G1 = '00000000-0000-7000-8000-000000000001';
    private const string G2 = '00000000-0000-7000-8000-000000000002';
    private const string G3 = '00000000-0000-7000-8000-000000000003';
    private const string G4 = '00000000-0000-7000-8000-000000000004';
    private const string G5 = '00000000-0000-7000-8000-000000000005';

    /** essential spending, savings rate and budget surplus of 2026-07 to 2026-11 */
    private const array FIGURES = [
        '2026-07' => ['1250.00', '0.12', '460.00'],
        '2026-08' => ['1450.00', '0.09', '460.00'],
        '2026-09' => ['1100.00', '0.15', '460.00'],
        '2026-10' => ['1300.00', '0.10', '460.00'],
        '2026-11' => ['1210.00', '0.04', '500.00'],
    ];

    public function testTheWorkedExampleReturnsTheListedItemsGapsAndStatuses(): void
    {
        $result = $this->evaluate()('2026-07', '2026-11');

        self::assertSame(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_map(static fn ($period) => $period->period, $result->months));
        $axis = [];
        $rate = [];
        foreach ($result->months as $month) {
            self::assertCount(2, $month->items);
            foreach ($month->items as $item) {
                'AXIS_SPENDING' === $item->metric ? $axis[$month->period] = $item : $rate[$month->period] = $item;
            }
        }
        self::assertSame([self::G1, '1200.00', '-50.00', 'MISSED', []], self::row($axis['2026-07']));
        self::assertSame([self::G2, '1500.00', '50.00', 'MET', [self::G1]], self::row($axis['2026-08']));
        self::assertSame([self::G1, '1200.00', '100.00', 'MET', []], self::row($axis['2026-09']));
        self::assertSame([self::G1, '1200.00', '-100.00', 'MISSED', []], self::row($axis['2026-10']));
        self::assertSame([self::G1, '1200.00', '-10.00', 'MISSED', []], self::row($axis['2026-11']));
        self::assertSame('axis:ESSENTIAL', $axis['2026-07']->columnKey);
        self::assertSame([self::G3, '0.10', '0.02', 'MET', []], self::row($rate['2026-07']));
        self::assertSame([self::G3, '0.10', '-0.01', 'MISSED', []], self::row($rate['2026-08']));
        self::assertSame([self::G3, '0.10', '0.05', 'MET', []], self::row($rate['2026-09']));
        self::assertSame([self::G3, '0.10', '0.00', 'MET', []], self::row($rate['2026-10']));
        self::assertSame([self::G3, '0.10', '-0.06', 'IN_PROGRESS', []], self::row($rate['2026-11']));
        self::assertSame('10.00', $rate['2026-07']->target?->percentDisplay);
        self::assertSame('cashSavingsRate', $rate['2026-07']->columnKey);

        self::assertSame(['2026'], array_map(static fn ($period) => $period->period, $result->years));
        $year = $result->years[0]->items[0];
        self::assertSame([self::G4, '6000.00', '-900.00', 'IN_PROGRESS', []], self::row($year));
        self::assertSame('5100.00', $year->achieved?->value);
        self::assertSame(['expected' => '5500.00', 'status' => 'BEHIND'], $year->pace);
        self::assertSame('budgetSurplus', $year->columnKey);
    }

    public function testAnAmountCeilingToleranceWidensTheBoundAndKeepsTheGapAgainstTheTarget(): void
    {
        $tolerant = GoalVersion::amount(self::G1, WorkspaceFixture::own(), self::G1, 2, '2026-07', DecimalValue::fromString('1200.00'), null, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID, tolerance: DecimalValue::fromString('50.00'));
        $result = $this->evaluate(extraVersions: [$tolerant])('2026-07', '2026-11');
        $axis = [];
        foreach ($result->months as $month) {
            foreach ($month->items as $item) {
                if ('AXIS_SPENDING' === $item->metric) {
                    $axis[$month->period] = $item;
                }
            }
        }

        // 1250.00 against 1200.00 + 50.00: equality at the bound is met, and the gap stays measured from the target.
        self::assertSame([self::G1, '1200.00', '-50.00', 'MET', []], self::row($axis['2026-07']));
        self::assertTrue($axis['2026-07']->withinTolerance);
        self::assertSame('50.00', $axis['2026-07']->tolerance?->value);
        self::assertSame('EUR', $axis['2026-07']->tolerance->assetCode);
        self::assertSame('50.00', $axis['2026-07']->operands['tolerance']);
        self::assertSame('1250.00', $axis['2026-07']->achieved?->value);
        // 1100.00 respects the target: nothing is within tolerance, the margin is still shown.
        self::assertSame('MET', $axis['2026-09']->status);
        self::assertFalse($axis['2026-09']->withinTolerance);
        self::assertSame('50.00', $axis['2026-09']->tolerance?->value);
        // 1300.00 is past 1250.00.
        self::assertSame('MISSED', $axis['2026-10']->status);
        self::assertFalse($axis['2026-10']->withinTolerance);
        // 1210.00 in the running month stays in progress, inside the margin.
        self::assertSame('IN_PROGRESS', $axis['2026-11']->status);
        self::assertTrue($axis['2026-11']->withinTolerance);
    }

    public function testARateMinimumToleranceIsARatioFigureAndStrictGoalsCarryNone(): void
    {
        $tolerant = GoalVersion::rate(self::G3, WorkspaceFixture::own(), self::G3, 2, '2026-01', DecimalValue::fromString('0.10'), new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID, tolerance: DecimalValue::fromString('0.01'));
        $result = $this->evaluate(extraVersions: [$tolerant])('2026-07', '2026-11');
        $rate = [];
        foreach ($result->months as $month) {
            foreach ($month->items as $item) {
                if ('SAVINGS_RATE' === $item->metric) {
                    $rate[$month->period] = $item;
                }
            }
        }

        // 0.09 against 0.10 - 0.01 is met at the bound; the strict version of this month was MISSED.
        self::assertSame([self::G3, '0.10', '-0.01', 'MET', []], self::row($rate['2026-08']));
        self::assertTrue($rate['2026-08']->withinTolerance);
        self::assertSame('0.01', $rate['2026-08']->tolerance?->ratio);
        self::assertSame('1.00', $rate['2026-08']->tolerance->percentDisplay);
        self::assertSame('0.01', $rate['2026-08']->operands['tolerance']);
        // 0.04 is far below the bound while the month runs: negative, not within.
        self::assertSame('IN_PROGRESS', $rate['2026-11']->status);
        self::assertFalse($rate['2026-11']->withinTolerance);

        $strict = $this->evaluate()('2026-08', '2026-08');
        $item = array_values(array_filter($strict->months[0]->items, static fn (GoalEvaluationView $item): bool => 'SAVINGS_RATE' === $item->metric))[0];
        self::assertSame('MISSED', $item->status);
        self::assertNull($item->tolerance);
        self::assertNull($item->operands['tolerance']);
        self::assertFalse($item->withinTolerance);
    }

    public function testANonCalculableEvaluationHasNoWithinToleranceVerdict(): void
    {
        $tolerant = GoalVersion::amount(self::G1, WorkspaceFixture::own(), self::G1, 2, '2026-07', DecimalValue::fromString('1200.00'), null, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID, tolerance: DecimalValue::fromString('50.00'));
        $result = $this->evaluate(extraVersions: [$tolerant], withNoDataMonth: '2026-09')('2026-09', '2026-09');
        $item = array_values(array_filter($result->months[0]->items, static fn (GoalEvaluationView $item): bool => 'AXIS_SPENDING' === $item->metric))[0];

        self::assertSame('NOT_CALCULABLE', $item->status);
        self::assertNull($item->withinTolerance);
    }

    public function testARevisionTakesEffectFromItsMonthAndLeavesThePastAtItsOldTarget(): void
    {
        $revised = self::amountVersion(self::G1, 2, '2026-12', '1100.00');
        $result = $this->evaluate(extraVersions: [$revised])('2026-07', '2026-11');

        foreach ($result->months as $month) {
            $axis = array_values(array_filter($month->items, static fn (GoalEvaluationView $item): bool => 'AXIS_SPENDING' === $item->metric))[0];
            self::assertSame('2026-08' === $month->period ? '1500.00' : '1200.00', $axis->target?->value);
            self::assertSame(1, $axis->versionNo);
        }
    }

    public function testARevisionAlreadyInEffectChangesTheTargetFromItsMonth(): void
    {
        $revised = self::amountVersion(self::G1, 2, '2026-10', '1100.00');
        $result = $this->evaluate(extraVersions: [$revised])('2026-09', '2026-11');

        $targets = array_map(static fn ($month): ?string => array_values(array_filter($month->items, static fn (GoalEvaluationView $item): bool => 'AXIS_SPENDING' === $item->metric))[0]->target?->value, $result->months);
        self::assertSame(['1200.00', '1100.00', '1100.00'], $targets);
    }

    public function testAMonthWithoutDataIsNotCalculableAndNeverMissed(): void
    {
        $result = $this->evaluate(withNoDataMonth: '2026-09')('2026-09', '2026-09');

        foreach ($result->months[0]->items as $item) {
            self::assertSame('NOT_CALCULABLE', $item->status);
            self::assertSame('NO_DATA', $item->reason);
            self::assertNull($item->achieved);
            self::assertNull($item->gap);
        }
    }

    public function testAnOversizedOrInvertedRangeIsRefused(): void
    {
        foreach ([['2026-01', '2036-02'], ['2026-05', '2026-04'], ['2026-13', '2026-14'], ['x', 'y']] as [$from, $to]) {
            try {
                $this->evaluate()($from, $to);
                self::fail('A bad range is refused.');
            } catch (GoalInputError $error) {
                self::assertSame('invalid_goal_query', $error->errorCode);
            }
        }
    }

    public function testExactlyOneHundredAndTwentyMonthsAreAccepted(): void
    {
        $result = $this->evaluate()('2026-01', '2035-12');

        self::assertCount(11, $result->months);
        self::assertSame(['2026'], array_map(static fn ($period) => $period->period, $result->years));
    }

    public function testTheYearGranularityLeavesMonthsEmptyAndKeepsTheYearFigures(): void
    {
        $both = $this->evaluate()('2026-01', '2026-11');
        $result = $this->evaluate()('2026-01', '2026-11', GoalEvaluationGranularity::YEAR);

        self::assertSame([], $result->months);
        self::assertEquals($both->years, $result->years);
        self::assertSame('5100.00', $result->years[0]->items[0]->achieved?->value);
    }

    public function testTheMonthGranularityLeavesYearsEmpty(): void
    {
        $result = $this->evaluate()('2026-07', '2026-08', GoalEvaluationGranularity::MONTH);

        self::assertCount(2, $result->months);
        self::assertSame([], $result->years);
    }

    public function testTheDefaultGranularityIsBoth(): void
    {
        $result = $this->evaluate()('2026-07', '2026-08');

        self::assertCount(2, $result->months);
        self::assertCount(1, $result->years);
    }

    public function testThirtyYearsAreAcceptedForTheYearGranularityAndThirtyOneAreRefused(): void
    {
        $result = $this->evaluate()('2026-01', '2055-12', GoalEvaluationGranularity::YEAR);
        self::assertSame([], $result->months);
        self::assertCount(1, $result->years);

        foreach ([['2026-01', '2056-12', GoalEvaluationGranularity::YEAR], ['2026-01', '2036-01', GoalEvaluationGranularity::MONTH], ['2026-01', '2036-01', GoalEvaluationGranularity::BOTH]] as [$from, $to, $granularity]) {
            try {
                $this->evaluate()($from, $to, $granularity);
                self::fail('A range over the bound of its granularity is refused.');
            } catch (GoalInputError $error) {
                self::assertSame('invalid_goal_query', $error->errorCode);
            }
        }
    }

    public function testAForeignWorkspaceNeverSeesTheGoalsOfAnother(): void
    {
        $result = $this->evaluate(callerWorkspace: WorkspaceFixture::other())('2026-07', '2026-11');

        foreach ($result->months as $month) {
            self::assertSame([], $month->items);
        }
        self::assertSame([], $result->years[0]->items);
    }

    public function testAFutureRangeIsEmpty(): void
    {
        $result = $this->evaluate()('2026-12', '2027-03');

        self::assertSame([], $result->months);
        self::assertSame([], $result->years);
    }

    public function testAYearEqualsTheAnnualAggregateOfTheSameColumn(): void
    {
        $result = $this->evaluate()('2026-01', '2026-11');

        $year = $result->years[0]->items[0];
        // January to June are 460.00 each in the fixture: 6 x 460.00 + 2300.00 (July to October 4 x 460.00 plus November 500.00).
        self::assertSame('5100.00', $year->achieved?->value);
        self::assertSame('5100.00', $year->operands['achieved']);
    }

    public function testAYearlyRateIsRecomputedFromSummedNumeratorAndDenominatorLikeTheAnnualReport(): void
    {
        $result = $this->evaluate(withYearlyRate: true)('2026-01', '2026-11');

        $rate = array_values(array_filter($result->years[0]->items, static fn (GoalEvaluationView $item): bool => 'SAVINGS_RATE' === $item->metric))[0];
        $months = self::months(null);
        $expected = AnnualColumnSeries::aggregate(new AnnualColumn('cashSavingsRate', 'Rate', ColumnKind::RATE, true), $months, IncompleteMonths::INCLUDE)->total?->toString();
        // 5100.00 saved over 11 x 4600.00 of income; the mean of the monthly rates would differ.
        self::assertSame($expected, $rate->achieved?->ratio);
        self::assertSame('0.100790513833992094861660', $rate->achieved?->ratio);
        self::assertSame('IN_PROGRESS', $rate->status);
        self::assertSame('cashSavingsRate', $rate->columnKey);
    }

    /** @return iterable<string, array{GoalMetric, string, string, MonthState, string, ?string}> */
    public static function totalCases(): iterable
    {
        yield 'income below minimum complete' => [GoalMetric::TOTAL_INCOME, '3000.00', '2950.00', MonthState::COMPLETE, 'MISSED', null];
        yield 'income equality complete' => [GoalMetric::TOTAL_INCOME, '3000.00', '3000.00', MonthState::COMPLETE, 'MET', null];
        yield 'income running' => [GoalMetric::TOTAL_INCOME, '3000.00', '2950.00', MonthState::PROVISIONAL, 'IN_PROGRESS', null];
        yield 'spending running exceeded' => [GoalMetric::TOTAL_SPENDING, '2500.00', '2500.01', MonthState::PROVISIONAL, 'MISSED', null];
        yield 'spending equality' => [GoalMetric::TOTAL_SPENDING, '2500.00', '2500.00', MonthState::COMPLETE, 'MET', null];
        yield 'income empty' => [GoalMetric::TOTAL_INCOME, '3000.00', '0', MonthState::NO_DATA, 'NOT_CALCULABLE', null];
        yield 'spending empty' => [GoalMetric::TOTAL_SPENDING, '2500.00', '0', MonthState::NO_DATA, 'NOT_CALCULABLE', null];
        yield 'income zero with data' => [GoalMetric::TOTAL_INCOME, '3000.00', '0', MonthState::COMPLETE, 'MISSED', null];
        yield 'spending zero with data' => [GoalMetric::TOTAL_SPENDING, '2500.00', '0', MonthState::COMPLETE, 'MET', null];
        yield 'income tolerance equality' => [GoalMetric::TOTAL_INCOME, '3000.00', '2950.00', MonthState::COMPLETE, 'MET', '50.00'];
        yield 'spending tolerance exceeded' => [GoalMetric::TOTAL_SPENDING, '2500.00', '2550.01', MonthState::PROVISIONAL, 'MISSED', '50.00'];
        yield 'large exact spending' => [GoalMetric::TOTAL_SPENDING, '99999999999999999999999999', '99999999999999999999999999.01', MonthState::COMPLETE, 'MISSED', null];
    }

    #[DataProvider('totalCases')]
    public function testTotalsReadReportCellsWithTheirFixedDirectionsAndExistingStatusRules(GoalMetric $metric, string $target, string $actual, MonthState $state, string $expectedStatus, ?string $tolerance): void
    {
        $column = GoalMetric::TOTAL_INCOME === $metric ? 'cashIncome' : 'budgetExpenses';
        $month = new CalendarMonth(2026, 11);
        $cells = [$column => new MonthlyMetricView($actual, 'EUR', null)];
        $months = [new AnnualMonth($month, $state, false, false, MonthState::NO_DATA === $state ? null : new MonthFigures($month->key(), 1, 'CURRENT', 0, '2026-11-30', $cells, [], [], []))];
        $goals = [new Goal(self::G1, WorkspaceFixture::own(), 'Total', GoalPeriodicity::MONTHLY, $metric, GoalScopeType::WORKSPACE, null, AssetCode::fromString('EUR'), '2026-11', null, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID)];
        $version = GoalVersion::amount(self::G1, WorkspaceFixture::own(), self::G1, 2, '2026-11', DecimalValue::fromString($target), null, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID, tolerance: null === $tolerance ? null : DecimalValue::fromString($tolerance));
        $result = $this->evaluate(extraVersions: [$version], customGoals: $goals, customMonths: $months)('2026-11', '2026-11');
        $item = $result->months[0]->items[0];

        self::assertSame($column, $item->columnKey);
        self::assertSame($expectedStatus, $item->status);
        self::assertSame(MonthState::NO_DATA === $state ? null : $actual, $item->achieved?->value);
        self::assertSame(MonthState::NO_DATA === $state ? 'NO_DATA' : null, $item->reason);
    }

    public function testAnnualTotalsEqualTheMatchingReportAggregates(): void
    {
        foreach ([GoalMetric::TOTAL_INCOME, GoalMetric::TOTAL_SPENDING] as $metric) {
            $column = GoalMetric::TOTAL_INCOME === $metric ? 'cashIncome' : 'budgetExpenses';
            $goal = new Goal(self::G1, WorkspaceFixture::own(), 'Annual total', GoalPeriodicity::YEARLY, $metric, GoalScopeType::WORKSPACE, null, AssetCode::fromString('EUR'), '2026', null, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
            $months = self::months(null);
            // A gap month remains zero in report totals, but its monthly goal is not calculable.
            $gap = $months[3];
            $months[3] = new AnnualMonth($gap->month, MonthState::COMPLETE, false, false, new MonthFigures($gap->month->key(), 1, 'CURRENT', 0, '2026-04-30', [$column => new MonthlyMetricView('0', 'EUR', null)], [], [], [], false));
            $months = array_values($months);
            $result = $this->evaluate(customGoals: [$goal], customMonths: $months)('2026-01', '2026-11');
            $expected = AnnualColumnSeries::aggregate(new AnnualColumn($column, 'Total', ColumnKind::FLOW, true), $months, IncompleteMonths::INCLUDE)->total?->toString();
            self::assertNotNull($expected);
            self::assertSame($expected, $result->years[0]->items[0]->achieved?->value);
            self::assertSame($column, $result->years[0]->items[0]->columnKey);
            $empty = $this->evaluate(customGoals: [$goal], customMonths: [])('2026-01', '2026-11');
            self::assertNull($empty->years[0]->items[0]->achieved);
            self::assertSame('NO_DATA', $empty->years[0]->items[0]->reason);
            $emptyMonths = [new AnnualMonth(new CalendarMonth(2026, 4), MonthState::COMPLETE, false, false, new MonthFigures('2026-04', 1, 'CURRENT', 0, '2026-04-30', [$column => new MonthlyMetricView('0', 'EUR', null)], [], [], [], false))];
            $empty = $this->evaluate(customGoals: [$goal], customMonths: $emptyMonths)('2026-01', '2026-11');
            self::assertNull($empty->years[0]->items[0]->achieved);
            self::assertSame('NO_DATA', $empty->years[0]->items[0]->reason);
        }
    }

    /** @return iterable<string, array{string, string, bool, string, ?string, ?string, ?string, ?bool}> */
    public static function spendingShareCases(): iterable
    {
        // 80% of 4000 = 3200; a one-point tolerance adds 40, inclusive.
        yield 'tolerance boundary' => ['4000.00', '3240.00', true, 'MET', null, '3200.0000', '40.0000', true];
        yield 'tolerance exceeded by a cent' => ['4000.00', '3240.01', true, 'MISSED', null, '3200.0000', '40.0000', false];
        yield 'zero income with transactions' => ['0', '10', true, 'NOT_CALCULABLE', 'ZERO_CASH_INCOME', null, null, null];
        yield 'empty period' => ['0', '0', false, 'NOT_CALCULABLE', 'NO_DATA', null, null, null];
        yield 'large exact target' => ['99999999999999999999999999', '79999999999999999999999999.20', true, 'MET', null, '79999999999999999999999999.20', '999999999999999999999999.99', false];
    }

    #[DataProvider('spendingShareCases')]
    public function testTotalSpendingShareUsesExactPeriodIncome(string $income, string $spent, bool $hasTransactions, string $status, ?string $reason, ?string $target, ?string $tolerance, ?bool $withinTolerance): void
    {
        $month = new CalendarMonth(2026, 10);
        $cells = ['cashIncome' => new MonthlyMetricView($income, 'EUR', null), 'budgetExpenses' => new MonthlyMetricView($spent, 'EUR', null)];
        $months = [new AnnualMonth($month, MonthState::COMPLETE, false, false, new MonthFigures($month->key(), 1, 'CURRENT', 0, '2026-10-31', $cells, [], [], [], $hasTransactions))];
        $goal = new Goal(self::G1, WorkspaceFixture::own(), 'Spending share', GoalPeriodicity::MONTHLY, GoalMetric::TOTAL_SPENDING, GoalScopeType::WORKSPACE, null, AssetCode::fromString('EUR'), '2026-01', null, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
        $version = GoalVersion::share(self::G1, WorkspaceFixture::own(), self::G1, 2, '2026-01', GoalValueType::INCOME_SHARE, DecimalValue::fromString('0.80'), new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID, tolerance: DecimalValue::fromString('0.01'));
        $item = $this->evaluate(extraVersions: [$version], customGoals: [$goal], customMonths: $months)('2026-10', '2026-10')->months[0]->items[0];

        self::assertSame($status, $item->status);
        self::assertSame($reason, $item->reason);
        self::assertSame($target, $item->target?->value);
        self::assertSame($tolerance, $item->tolerance?->value);
        self::assertSame($withinTolerance, $item->withinTolerance);
        self::assertSame('budgetExpenses', $item->columnKey);
    }

    public function testAnnualTotalSpendingShareUsesSummedIncomeAndTolerance(): void
    {
        $goal = new Goal(self::G1, WorkspaceFixture::own(), 'Annual spending share', GoalPeriodicity::YEARLY, GoalMetric::TOTAL_SPENDING, GoalScopeType::WORKSPACE, null, AssetCode::fromString('EUR'), '2026', null, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
        $version = GoalVersion::share(self::G1, WorkspaceFixture::own(), self::G1, 2, '2026-01', GoalValueType::INCOME_SHARE, DecimalValue::fromString('0.80'), new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID, tolerance: DecimalValue::fromString('0.01'));
        $months = [];
        foreach ([[1, '2000.00', '2000.00'], [2, '3000.00', '2050.00']] as [$number, $income, $spent]) {
            $month = new CalendarMonth(2026, $number);
            $months[] = new AnnualMonth($month, MonthState::COMPLETE, false, false, new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), ['cashIncome' => new MonthlyMetricView($income, 'EUR', null), 'budgetExpenses' => new MonthlyMetricView($spent, 'EUR', null)], [], [], []));
        }
        $item = $this->evaluate(extraVersions: [$version], customGoals: [$goal], customMonths: $months)('2026-01', '2026-11')->years[0]->items[0];

        // Annual income 5000 x 80% = 4000; 1 point = 50. Spending 4050 is inside the margin.
        self::assertSame('4000.0000', $item->target?->value);
        self::assertSame('4050.00', $item->achieved?->value);
        self::assertSame('50.0000', $item->tolerance?->value);
        self::assertTrue($item->withinTolerance);
        self::assertSame('IN_PROGRESS', $item->status);
        self::assertSame('budgetExpenses', $item->columnKey);
        $empty = $this->evaluate(extraVersions: [$version], customGoals: [$goal], customMonths: [])('2026-01', '2026-11')->years[0]->items[0];
        self::assertNull($empty->target);
        self::assertNull($empty->achieved);
        self::assertSame('NO_DATA', $empty->reason);
    }

    /** @return array{0: string, 1: ?string, 2: ?string, 3: string, 4: list<string>} */
    private static function row(GoalEvaluationView $item): array
    {
        $target = $item->target;
        $figure = null === $target ? null : ($target->isRate() ? $target->ratio : $target->value);

        return [$item->goalId, $figure, $item->gap, $item->status, $item->overrides];
    }

    /**
     * @param list<GoalVersion>  $extraVersions
     * @param ?list<Goal>        $customGoals
     * @param ?list<AnnualMonth> $customMonths
     */
    private function evaluate(array $extraVersions = [], ?string $withNoDataMonth = null, ?WorkspaceScope $callerWorkspace = null, bool $withYearlyRate = false, ?array $customGoals = null, ?array $customMonths = null): ListGoalEvaluations
    {
        $own = WorkspaceFixture::own();
        $caller = $this->createStub(CallerWorkspace::class);
        $caller->method('resolve')->willReturn($callerWorkspace ?? $own);
        $goals = $this->createStub(GoalRepository::class);
        $all = $customGoals ?? self::goals($withYearlyRate);
        $goals->method('covering')->willReturnCallback(static fn (WorkspaceScope $workspace, string $first, string $last): array => $workspace->equals($own)
            ? array_values(array_filter($all, static fn (Goal $goal): bool => $goal->firstMonth() <= $last && (null === $goal->lastMonth() || $goal->lastMonth() >= $first)))
            : []);
        $versions = $this->createStub(GoalVersionRepository::class);
        $versions->method('listByGoals')->willReturnCallback(static function (WorkspaceScope $workspace, array $goalIds) use ($extraVersions): array {
            $byGoal = [];
            foreach ($goalIds as $goalId) {
                \assert(\is_string($goalId));
                $byGoal[$goalId] = [...array_values(array_filter($extraVersions, static fn (GoalVersion $version): bool => $version->goalId === $goalId)), self::version($goalId)];
            }

            return $byGoal;
        });
        $source = new class($customMonths ?? self::months($withNoDataMonth)) implements GoalMonthSource {
            /** @param list<AnnualMonth> $months */
            public function __construct(private readonly array $months)
            {
            }

            public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end): array
            {
                return array_values(array_filter($this->months, static fn (AnnualMonth $month): bool => $month->month->key() >= $start->key() && $month->month->key() <= $end->key()));
            }
        };
        $categories = $this->createStub(CategoryRepository::class);
        $scope = new ResolveGoalScope(new ReadCategoryReference($categories), new ReadAccountReference(new InMemoryAccountRepository()));
        $calendar = self::calendar();
        $status = new ReadGoalStatus($caller, $goals, $versions, $source, new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs(new class implements MonthlyTransferPairReader {
            public function read(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
            {
                return [];
            }
        })), $calendar, $scope, new ReadBudgetCategoryFacts($categories));

        return new ListGoalEvaluations($caller, $goals, $versions, $status, $scope, $calendar);
    }

    /** @return list<Goal> */
    private static function goals(bool $withYearlyRate = false): array
    {
        $eur = AssetCode::fromString('EUR');
        $make = static fn (string $id, string $created, GoalPeriodicity $periodicity, GoalMetric $metric, GoalScopeType $scope, ?string $ref, string $start, ?string $end): Goal => new Goal($id, WorkspaceFixture::own(), 'Goal '.substr($id, -1), $periodicity, $metric, $scope, $ref, $eur, $start, $end, 1, new \DateTimeImmutable($created), WorkspaceFixture::OWNER_ID);

        $yearlyRate = $withYearlyRate ? [$make(self::G5, '2026-01-04', GoalPeriodicity::YEARLY, GoalMetric::SAVINGS_RATE, GoalScopeType::WORKSPACE, null, '2026', '2026')] : [];

        return [
            ...$yearlyRate,
            $make(self::G1, '2026-01-01', GoalPeriodicity::MONTHLY, GoalMetric::AXIS_SPENDING, GoalScopeType::AXIS, 'ESSENTIAL', '2026-01', null),
            $make(self::G2, '2026-07-20', GoalPeriodicity::MONTHLY, GoalMetric::AXIS_SPENDING, GoalScopeType::AXIS, 'ESSENTIAL', '2026-08', '2026-08'),
            $make(self::G3, '2026-01-02', GoalPeriodicity::MONTHLY, GoalMetric::SAVINGS_RATE, GoalScopeType::WORKSPACE, null, '2026-01', null),
            $make(self::G4, '2026-01-03', GoalPeriodicity::YEARLY, GoalMetric::BUDGET_SURPLUS, GoalScopeType::WORKSPACE, null, '2026', '2026'),
        ];
    }

    private static function version(string $goalId): GoalVersion
    {
        $created = new \DateTimeImmutable('2026-01-01');

        return match ($goalId) {
            self::G1 => self::amountVersion($goalId, 1, '2026-01', '1200.00'),
            self::G2 => self::amountVersion($goalId, 1, '2026-08', '1500.00'),
            self::G5 => GoalVersion::rate($goalId, WorkspaceFixture::own(), $goalId, 1, '2026-01', DecimalValue::fromString('0.10'), $created, WorkspaceFixture::OWNER_ID),
            self::G3 => GoalVersion::rate($goalId, WorkspaceFixture::own(), $goalId, 1, '2026-01', DecimalValue::fromString('0.10'), $created, WorkspaceFixture::OWNER_ID),
            default => GoalVersion::amount($goalId, WorkspaceFixture::own(), $goalId, 1, '2026-01', DecimalValue::fromString('6000.00'), GoalSchedule::linear(DecimalValue::fromString('6000.00'), 2), $created, WorkspaceFixture::OWNER_ID),
        };
    }

    private static function amountVersion(string $goalId, int $number, string $from, string $amount): GoalVersion
    {
        return GoalVersion::amount($goalId.'', WorkspaceFixture::own(), $goalId, $number, $from, DecimalValue::fromString($amount), null, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
    }

    /** @return list<AnnualMonth> */
    private static function months(?string $noData): array
    {
        $months = [];
        for ($index = 1; $index <= 11; ++$index) {
            $month = new CalendarMonth(2026, $index);
            [$essential, $rate, $surplus] = self::FIGURES[$month->key()] ?? ['1000.00', '0.10', '460.00'];
            $cells = [
                'cashIncome' => new MonthlyMetricView('4600.00', 'EUR', null),
                'budgetExpenses' => new MonthlyMetricView('2500.00', 'EUR', null),
                'axis:ESSENTIAL' => new MonthlyMetricView($essential, 'EUR', null),
                'cashSavingsRate' => new MonthlyMetricView($rate, null, null),
                'budgetSurplus' => new MonthlyMetricView($surplus, 'EUR', null),
            ];
            $state = $month->key() === $noData ? MonthState::NO_DATA : (11 === $index ? MonthState::PROVISIONAL : MonthState::COMPLETE);
            $months[] = new AnnualMonth($month, $state, false, false, MonthState::NO_DATA === $state ? null : new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), $cells, [], [], []));
        }

        return $months;
    }

    private static function calendar(): WorkspaceCalendar
    {
        $context = new class implements CallerWorkspaceContext {
            public function resolveContext(): WorkspaceContext
            {
                return new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, true);
            }
        };
        $timezones = new class implements WorkspaceTimezoneReader {
            public function timezone(WorkspaceScope $workspace): string
            {
                return 'Europe/Paris';
            }
        };

        return new WorkspaceCalendar(new MockClock('2026-11-03T12:00:00+01:00'), $context, $timezones);
    }
}
