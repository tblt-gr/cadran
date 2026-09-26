<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Application\AllocationFigure;
use App\Module\Reporting\Application\AnnualAllocationView;
use App\Module\Reporting\Application\AnnualCatalogue;
use App\Module\Reporting\Application\AnnualCharts;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\AggregateQuality;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use PHPUnit\Framework\TestCase;

final class AnnualChartsTest extends TestCase
{
    public function testTheTopCategoriesKeepSixAndSumTheRestIntoOtherWithExactShares(): void
    {
        // 8 categories, 100 each in January and 50 in February, but c8 is 1 and 2: total 1000.15.
        $ids = array_map(static fn (int $n): string => 'c'.$n, range(1, 8));
        $labels = [];
        $january = [];
        $february = [];
        foreach ($ids as $index => $id) {
            $labels[$id] = 'Categorie '.($index + 1);
            $january['category:'.$id] = new MonthlyMetricView('c8' === $id ? '1.05' : '100', 'EUR', null);
            $february['category:'.$id] = new MonthlyMetricView('c8' === $id ? '2.1' : (('c7' === $id) ? '10' : '50'), 'EUR', null);
        }
        $months = [$this->month(1, $january, $ids), $this->month(2, $february, $ids)];
        $total = DecimalValue::fromString('1000.15');

        $chart = AnnualCharts::topExpenseCategories($months, new AnnualCatalogue($labels, [], []), $this->aggregate($total, null));

        self::assertNull($chart->reason);
        self::assertCount(6, $chart->items);
        self::assertSame('c1', $chart->items[0]->categoryId);
        self::assertSame('150', $chart->items[0]->total);
        // c7 (110) and c8 (3.15) are the rest: 113.15.
        self::assertNotNull($chart->other);
        self::assertSame('113.15', $chart->other->total);
        self::assertSame('Autres', $chart->other->label);
        self::assertStringStartsWith('0.1131', $chart->other->share);
    }

    public function testANullOrZeroBudgetTotalGivesNoChartAndAReason(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);

        $missing = AnnualCharts::topExpenseCategories([], $catalogue, $this->aggregate(null, AggregateReason::MIXED_METRIC_POLICIES));
        $zero = AnnualCharts::topExpenseCategories([], $catalogue, $this->aggregate(DecimalValue::zero(), null));

        self::assertSame('MIXED_METRIC_POLICIES', $missing->reason);
        self::assertSame([], $missing->items);
        self::assertSame('ZERO_DENOMINATOR', $zero->reason);
    }

    public function testACategoryWithoutMovementSpentNothingButAnotherGapIsNotZero(): void
    {
        $quiet = [$this->month(1, ['category:c1' => new MonthlyMetricView(null, null, 'NO_MOVEMENTS'), 'category:c2' => new MonthlyMetricView('40', 'EUR', null)], ['c1', 'c2'])];

        $chart = AnnualCharts::topExpenseCategories($quiet, new AnnualCatalogue(['c1' => 'A', 'c2' => 'B'], [], []), $this->aggregate(DecimalValue::fromString('40'), null));

        self::assertNull($chart->reason);
        self::assertCount(1, $chart->items);
        self::assertSame('c2', $chart->items[0]->categoryId);
        self::assertSame('1.000000000000000000000000', $chart->items[0]->share);
    }

    public function testAnExpenseCategoryWithNoCellAtAllCountsAsNoMovement(): void
    {
        $months = [$this->month(1, ['category:c2' => new MonthlyMetricView('40', 'EUR', null)], ['c1', 'c2'])];

        $chart = AnnualCharts::topExpenseCategories($months, new AnnualCatalogue(['c1' => 'Archivée', 'c2' => 'B'], [], []), $this->aggregate(DecimalValue::fromString('40'), null));

        self::assertNull($chart->reason);
        self::assertCount(1, $chart->items);
    }

    public function testAGroupWithoutValueIsKeptWithItsReasonAndTheChartCarriesItsAsset(): void
    {
        $month = $this->month(8, [], [], [
            new AllocationFigure('g1', '5000', 'EUR', '0.5', null),
            new AllocationFigure('g2', null, null, null, 'MISSING_VALUATION'),
        ]);

        $allocation = AnnualCharts::allocation([$month], IncompleteMonths::EXCLUDE, new AnnualCatalogue([], ['g1' => 'A', 'g2' => 'B'], []));

        self::assertCount(2, $allocation->items);
        self::assertNull($allocation->items[1]->value);
        self::assertNull($allocation->items[1]->share);
        self::assertSame('MISSING_VALUATION', $allocation->items[1]->reason);
        self::assertSame('EUR', $allocation->assetCode);
    }

    public function testTheFlowsAndNetWorthSeriesCoverEveryMonthWhateverTheColumns(): void
    {
        $months = [
            $this->month(1, ['cashIncome' => new MonthlyMetricView('100', 'EUR', null), 'budgetExpenses' => new MonthlyMetricView('40', 'EUR', null), 'budgetSurplus' => new MonthlyMetricView('60', 'EUR', null), 'endNetWorth' => new MonthlyMetricView(null, null, 'MISSING_VALUATION')], []),
            new AnnualMonth(new CalendarMonth(2026, 12), MonthState::FUTURE, false, false, null),
        ];

        $flows = AnnualCharts::flows($months);
        $netWorth = AnnualCharts::netWorth($months);

        self::assertSame('EUR', $flows->assetCode);
        self::assertSame('100', $flows->months[0]->cashIncome->value);
        self::assertSame('FUTURE', $flows->months[1]->cashIncome->reason);
        self::assertNull($netWorth->months[0]->value->value);
        self::assertSame('MISSING_VALUATION', $netWorth->months[0]->value->reason);
        self::assertCount(2, $netWorth->months);
    }

    public function testAMissingCategoryFigureMakesTheChartNonCalculableNotZero(): void
    {
        $months = [$this->month(1, ['category:c1' => new MonthlyMetricView(null, null, 'UNKNOWN_METRIC_POLICY')], ['c1'])];

        $chart = AnnualCharts::topExpenseCategories($months, new AnnualCatalogue(['c1' => 'Loyer'], [], []), $this->aggregate(DecimalValue::fromString('10'), null));

        self::assertSame('MISSING_MONTH_VALUE', $chart->reason);
    }

    public function testTheAllocationReadsTheLastCountedMonthAndSkipsTheRunningOneWhenExcluded(): void
    {
        $complete = $this->month(8, [], [], [new AllocationFigure('g1', '5000', 'EUR', '0.5', null)]);
        $running = new AnnualMonth(new CalendarMonth(2026, 9), MonthState::PROVISIONAL, false, false, $this->figures(9, [], [], [new AllocationFigure('g1', '6000', 'EUR', '0.6', null)]));
        $catalogue = new AnnualCatalogue([], ['g1' => 'Liquidités'], []);

        $excluded = AnnualCharts::allocation([$complete, $running], IncompleteMonths::EXCLUDE, $catalogue);
        $included = AnnualCharts::allocation([$complete, $running], IncompleteMonths::INCLUDE, $catalogue);

        self::assertSame('5000', $excluded->items[0]->value);
        self::assertSame('Liquidités', $excluded->items[0]->label);
        self::assertSame('6000', $included->items[0]->value);
        self::assertSame('0.6', $included->items[0]->share);
    }

    public function testAnAllocationWithoutACountedMonthIsEmptyWithItsReason(): void
    {
        $allocation = AnnualCharts::allocation([], IncompleteMonths::EXCLUDE, new AnnualCatalogue([], [], []));

        self::assertInstanceOf(AnnualAllocationView::class, $allocation);
        self::assertSame('EMPTY_POPULATION', $allocation->reason);
        self::assertNull($allocation->asOf);
    }

    /**
     * @param array<string, MonthlyMetricView> $cells
     * @param list<string>                     $categoryIds
     * @param list<AllocationFigure>           $allocation
     */
    private function month(int $number, array $cells, array $categoryIds, array $allocation = []): AnnualMonth
    {
        return new AnnualMonth(new CalendarMonth(2026, $number), MonthState::COMPLETE, false, false, $this->figures($number, $cells, $categoryIds, $allocation));
    }

    /**
     * @param array<string, MonthlyMetricView> $cells
     * @param list<string>                     $categoryIds
     * @param list<AllocationFigure>           $allocation
     */
    private function figures(int $number, array $cells, array $categoryIds, array $allocation): MonthFigures
    {
        return new MonthFigures(sprintf('2026-%02d', $number), 1, 'CURRENT', 0, sprintf('2026-%02d-28', $number), $cells, $categoryIds, $allocation);
    }

    private function aggregate(?DecimalValue $total, ?AggregateReason $reason): Aggregate
    {
        return new Aggregate(ColumnKind::FLOW, $total, $reason, null, null, null, null, null, null, null, null, null, 1, [], [], [], IncompleteMonths::EXCLUDE, AggregateQuality::COMPLETE, 'f');
    }
}
