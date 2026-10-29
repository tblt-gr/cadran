<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Application\AllocationFigure;
use App\Module\Reporting\Application\AnnualAllocationItemView;
use App\Module\Reporting\Application\AnnualAllocationView;
use App\Module\Reporting\Application\AnnualCatalogue;
use App\Module\Reporting\Application\AnnualCategoryShareView;
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
            $january['ownCategory:'.$id] = new MonthlyMetricView('c8' === $id ? '1.05' : '100', 'EUR', null);
            $february['ownCategory:'.$id] = new MonthlyMetricView('c8' === $id ? '2.1' : (('c7' === $id) ? '10' : '50'), 'EUR', null);
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
        // The drill-down lists every aggregated category, largest first, and sums back to other exactly.
        self::assertSame(['c7', 'c8'], array_map(static fn (AnnualCategoryShareView $i): ?string => $i->categoryId, $chart->other->items));
        self::assertSame(['Categorie 7', 'Categorie 8'], array_map(static fn (AnnualCategoryShareView $i): string => $i->label, $chart->other->items));
        self::assertSame(['110', '3.15'], array_map(static fn (AnnualCategoryShareView $i): string => $i->total, $chart->other->items));
        self::assertStringStartsWith('0.1099', $chart->other->items[0]->share);
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
        $quiet = [$this->month(1, ['ownCategory:c1' => new MonthlyMetricView(null, null, 'NO_MOVEMENTS'), 'ownCategory:c2' => new MonthlyMetricView('40', 'EUR', null)], ['c1', 'c2'])];

        $chart = AnnualCharts::topExpenseCategories($quiet, new AnnualCatalogue(['c1' => 'A', 'c2' => 'B'], [], []), $this->aggregate(DecimalValue::fromString('40'), null));

        self::assertNull($chart->reason);
        self::assertCount(1, $chart->items);
        self::assertSame('c2', $chart->items[0]->categoryId);
        self::assertSame('1.000000000000000000000000', $chart->items[0]->share);
    }

    public function testAnExpenseCategoryWithNoCellAtAllCountsAsNoMovement(): void
    {
        $months = [$this->month(1, ['ownCategory:c2' => new MonthlyMetricView('40', 'EUR', null)], ['c1', 'c2'])];

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

    public function testCategoryColorsAreCarriedAndAnUncoloredOrForeignCategoryHasNone(): void
    {
        // c1 own color, c2 inherited from its parent (already effective in the catalogue), c3 none,
        // c9 belongs to another workspace and is not in the catalogue at all.
        $catalogue = new AnnualCatalogue(
            ['c1' => 'Loyer', 'c2' => 'Sous-catégorie', 'c3' => 'Sans couleur'],
            [],
            [],
            [],
            ['c1' => '#112233', 'c2' => '#445566', 'c3' => null],
        );
        $cells = [
            'ownCategory:c1' => new MonthlyMetricView('30', 'EUR', null),
            'ownCategory:c2' => new MonthlyMetricView('20', 'EUR', null),
            'ownCategory:c3' => new MonthlyMetricView('10', 'EUR', null),
        ];
        $chart = AnnualCharts::topExpenseCategories([$this->month(1, $cells, ['c1', 'c2', 'c3'])], $catalogue, $this->aggregate(DecimalValue::fromString('60'), null));

        self::assertSame(['#112233', '#445566', null], array_map(static fn (AnnualCategoryShareView $item): ?string => $item->color, $chart->items));
        self::assertNull($catalogue->categoryColor('c9'));
    }

    public function testTheOtherBucketHasNoColorWhileItsCategoriesKeepTheirOwn(): void
    {
        $ids = array_map(static fn (int $n): string => 'c'.$n, range(1, 8));
        $colors = [];
        $cells = [];
        foreach ($ids as $index => $id) {
            $colors[$id] = 'c8' === $id ? null : sprintf('#00000%d', $index);
            $cells['ownCategory:'.$id] = new MonthlyMetricView((string) (80 - $index * 10), 'EUR', null);
        }
        $catalogue = new AnnualCatalogue(array_combine($ids, $ids), [], [], [], $colors);

        $chart = AnnualCharts::topExpenseCategories([$this->month(1, $cells, $ids)], $catalogue, $this->aggregate(DecimalValue::fromString('360'), null));

        self::assertNotNull($chart->other);
        self::assertNull($chart->other->color);
        self::assertSame(['#000006', null], array_map(static fn (AnnualCategoryShareView $item): ?string => $item->color, $chart->other->items));
    }

    /**
     * Net worth 2500.00: Investissement holds 100.00 directly, Bourse 900.00, Immobilier 500.00, so its
     * roll-up is 1500.00; Epargne holds 1000.00. Direct shares: 4 %, 36 %, 20 %, 40 %.
     */
    public function testTheAllocationCarriesRollUpAndDirectFiguresForBothGroupingModes(): void
    {
        $month = $this->month(8, [], [], [
            new AllocationFigure('i', '1500.00', 'EUR', '0.6', null, null, 1, '100.00', '0.04', null),
            new AllocationFigure('b', '900.00', 'EUR', '0.36', null, 'i', 2, '900.00', '0.36', null),
            new AllocationFigure('m', '500.00', 'EUR', '0.2', null, 'i', 2, '500.00', '0.2', null),
            new AllocationFigure('e', '1000.00', 'EUR', '0.4', null, null, 1, '1000.00', '0.4', null),
        ]);
        $catalogue = new AnnualCatalogue([], ['i' => 'Investissement', 'b' => 'Bourse', 'm' => 'Immobilier', 'e' => 'Épargne'], [], [], [], ['i' => '#aa0000', 'b' => '#bb0000']);

        $items = AnnualCharts::allocation([$month], IncompleteMonths::EXCLUDE, $catalogue)->items;

        self::assertSame([true, false, false, false], array_map(static fn (AnnualAllocationItemView $item): bool => $item->hasChildren, $items));
        self::assertSame([null, 'i', 'i', null], array_map(static fn (AnnualAllocationItemView $item): ?string => $item->parentId, $items));
        self::assertSame([1, 2, 2, 1], array_map(static fn (AnnualAllocationItemView $item): int => $item->depth, $items));
        self::assertSame(['1500.00', '900.00', '500.00', '1000.00'], array_map(static fn (AnnualAllocationItemView $item): ?string => $item->value, $items));
        self::assertSame(['100.00', '900.00', '500.00', '1000.00'], array_map(static fn (AnnualAllocationItemView $item): ?string => $item->directValue, $items));
        self::assertSame(['0.04', '0.36', '0.2', '0.4'], array_map(static fn (AnnualAllocationItemView $item): ?string => $item->directShare, $items));
        // Roots plus nothing else partition the net worth: 1500.00 + 1000.00; direct totals too: 100 + 900 + 500 + 1000.
        self::assertSame(['#aa0000', '#bb0000', null, null], array_map(static fn (AnnualAllocationItemView $item): ?string => $item->color, $items));
    }

    /** A loan of 200.25 sits in the sub-group: its direct value is negative and the root rolls it up. */
    public function testALiabilityInASubGroupKeepsItsSignedDirectAndRollUpFigures(): void
    {
        // Net worth 1300.25: Investissement holds 500.50 directly, Bourse the loan -200.25, Immobilier 1000.00.
        $month = $this->month(8, [], [], [
            new AllocationFigure('i', '1300.25', 'EUR', '1', null, null, 1, '500.50', '0.384935', null),
            new AllocationFigure('b', '-200.25', 'EUR', '-0.154009', null, 'i', 2, '-200.25', '-0.154009', null),
            new AllocationFigure('m', '1000.00', 'EUR', '0.769082', null, 'i', 2, '1000.00', '0.769082', null),
        ]);

        $items = AnnualCharts::allocation([$month], IncompleteMonths::EXCLUDE, new AnnualCatalogue([], ['i' => 'I', 'b' => 'B', 'm' => 'M'], []))->items;

        self::assertSame(['1300.25', '-200.25', '1000.00'], array_map(static fn (AnnualAllocationItemView $item): ?string => $item->value, $items));
        self::assertSame(['500.50', '-200.25', '1000.00'], array_map(static fn (AnnualAllocationItemView $item): ?string => $item->directValue, $items));
        self::assertSame([null, null, null], array_map(static fn (AnnualAllocationItemView $item): ?string => $item->color, $items));
    }

    public function testAnUnvaluedGroupKeepsItsDirectReasonAndAnOlderSnapshotFallsBackToTheRollUpReason(): void
    {
        $month = $this->month(8, [], [], [
            new AllocationFigure('g1', null, null, null, 'MISSING_VALUATION', null, 1, null, null, 'MISSING_VALUATION'),
            new AllocationFigure('g2', null, null, null, 'MIXED_ASSETS'),
            new AllocationFigure('g3', '0', 'EUR', '0', null, null, 1, '0', '0', null),
        ]);

        $items = AnnualCharts::allocation([$month], IncompleteMonths::EXCLUDE, new AnnualCatalogue([], [], []))->items;

        self::assertSame('MISSING_VALUATION', $items[0]->directReason);
        self::assertSame('MIXED_ASSETS', $items[1]->directReason);
        self::assertNull($items[2]->directReason);
        self::assertSame('0', $items[2]->directValue);
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

    /** December 2025 closes at 1000.00 and January 2026 at 850.00: the curve opens on 1000.00. */
    public function testTheNetWorthCurveOpensOnTheDecemberOfThePreviousYearAsItsOwnReportDrawsIt(): void
    {
        $previous = $this->yearOfNetWorth(2025, ['12' => '1000.00']);
        $months = $this->yearOfNetWorth(2026, ['01' => '850.00']);

        $netWorth = AnnualCharts::netWorth($months, $previous);

        self::assertNotNull($netWorth->opening);
        self::assertSame('2025-12', $netWorth->opening->month);
        self::assertSame('1000.00', $netWorth->opening->value->value);
        self::assertNull($netWorth->opening->value->reason);
        // Same value as the December point of the previous year's own report.
        $own = AnnualCharts::netWorth($previous);
        self::assertEquals($own->months[11], $netWorth->opening);
        self::assertSame('850.00', $netWorth->months[0]->value->value);
        self::assertCount(12, $netWorth->months);
        self::assertSame('EUR', $netWorth->assetCode);
    }

    public function testThereIsNoOpeningPointBeforeTheFirstMonthOfData(): void
    {
        $months = $this->yearOfNetWorth(2026, ['01' => '850.00']);
        $noFigures = array_map(static fn (int $number): AnnualMonth => new AnnualMonth(new CalendarMonth(2025, $number), MonthState::COMPLETE, false, false, null), range(1, 12));

        self::assertNull(AnnualCharts::netWorth($months, $noFigures)->opening);
        self::assertNull(AnnualCharts::netWorth($months, [])->opening);
        self::assertNull(AnnualCharts::netWorth($months)->opening);
    }

    public function testADecemberWithoutNetWorthIsAGapWithItsReasonNeverZero(): void
    {
        $previous = $this->yearOfNetWorth(2025, []);
        $previous[11] = $this->month(12, ['endNetWorth' => new MonthlyMetricView(null, null, 'MISSING_VALUATION')], []);
        $previous[11] = new AnnualMonth(new CalendarMonth(2025, 12), MonthState::COMPLETE, false, false, $previous[11]->figures);

        $opening = AnnualCharts::netWorth($this->yearOfNetWorth(2026, ['01' => '850.00']), array_values($previous))->opening;

        self::assertNotNull($opening);
        self::assertNull($opening->value->value);
        self::assertSame('MISSING_VALUATION', $opening->value->reason);
    }

    /**
     * @param array<array-key, string> $values closing net worth by two-digit month; other months have figures without a value
     *
     * @return list<AnnualMonth>
     */
    private function yearOfNetWorth(int $year, array $values): array
    {
        $months = [];
        foreach (range(1, 12) as $number) {
            $key = sprintf('%02d', $number);
            $metric = isset($values[$key]) ? new MonthlyMetricView($values[$key], 'EUR', null) : new MonthlyMetricView(null, null, 'NO_DATA');
            $figures = new MonthFigures(sprintf('%d-%s', $year, $key), 1, 'CURRENT', 0, sprintf('%d-%s-28', $year, $key), ['endNetWorth' => $metric], [], [], []);
            $months[] = new AnnualMonth(new CalendarMonth($year, $number), MonthState::COMPLETE, false, false, $figures);
        }

        return $months;
    }

    public function testAMissingCategoryFigureMakesTheChartNonCalculableNotZero(): void
    {
        $months = [$this->month(1, ['ownCategory:c1' => new MonthlyMetricView(null, null, 'UNKNOWN_METRIC_POLICY')], ['c1'])];

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
        return new MonthFigures(sprintf('2026-%02d', $number), 1, 'CURRENT', 0, sprintf('2026-%02d-28', $number), $cells, $categoryIds, $allocation, []);
    }

    private function aggregate(?DecimalValue $total, ?AggregateReason $reason): Aggregate
    {
        return new Aggregate(ColumnKind::FLOW, $total, $reason, null, null, null, null, null, null, null, null, null, 1, [], [], [], IncompleteMonths::EXCLUDE, AggregateQuality::COMPLETE, 'f');
    }
}
