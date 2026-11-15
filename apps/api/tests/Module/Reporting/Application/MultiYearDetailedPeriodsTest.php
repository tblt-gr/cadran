<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Application\ReportAccountReference;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Reporting\Application\AnnualCatalogue;
use App\Module\Reporting\Application\AnnualColumnSeries;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\AnnualPolicyView;
use App\Module\Reporting\Application\ComputedAnnualYear;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\MultiYearDetailedPeriods;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use PHPUnit\Framework\TestCase;

final class MultiYearDetailedPeriodsTest extends TestCase
{
    public function testAFlowIsSummedOverEveryMonthOfEveryYearOfTheRange(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $catalogue->column('cashIncome');
        self::assertNotNull($column);
        $computed = [
            2025 => $this->year(2025, $catalogue, [$this->month(2025, 12, ['cashIncome' => '500'])]),
            2026 => $this->year(2026, $catalogue, [
                $this->month(2026, 1, ['cashIncome' => '1000']),
                $this->month(2026, 2, ['cashIncome' => '3000']),
            ]),
        ];

        $detailed = (new MultiYearDetailedPeriods())->detailedPeriod([$column], $computed, 2025, 2026, IncompleteMonths::EXCLUDE);

        $view = $detailed['cashIncome'];
        self::assertSame('4500', $this->trim($view->total));
        self::assertNull($view->totalReason);
        self::assertSame('1500', $this->trim($view->average));
        self::assertSame('500', $this->trim($view->minimum?->value));
        self::assertSame('2025-12', $view->minimum?->month);
        self::assertSame('3000', $this->trim($view->maximum?->value));
        self::assertSame('2026-02', $view->maximum?->month);
        self::assertSame('COMPLETE', $view->quality);
    }

    public function testAMixedPolicyRangeIsNonCalculableAndNeverZero(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $catalogue->column('cashIncome');
        self::assertNotNull($column);
        $computed = [2026 => $this->year(2026, $catalogue, [
            $this->month(2026, 1, ['cashIncome' => '1000'], 1),
            $this->month(2026, 2, ['cashIncome' => '1000'], 2),
        ])];

        $view = (new MultiYearDetailedPeriods())->detailedPeriod([$column], $computed, 2026, 2026, IncompleteMonths::EXCLUDE)['cashIncome'];

        self::assertNull($view->total);
        self::assertNull($view->average);
        self::assertNull($view->median);
        self::assertNull($view->minimum);
        self::assertNull($view->maximum);
        self::assertSame('MIXED_METRIC_POLICIES', $view->totalReason);
        self::assertSame('PARTIAL', $view->quality);
    }

    public function testNoCountedMonthYieldsAnEmptyPopulation(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $catalogue->column('cashIncome');
        self::assertNotNull($column);
        $future = new AnnualMonth(new CalendarMonth(2026, 1), MonthState::FUTURE, false, false, null);

        $view = (new MultiYearDetailedPeriods())->detailedPeriod([$column], [2026 => $this->year(2026, $catalogue, [$future])], 2026, 2026, IncompleteMonths::EXCLUDE)['cashIncome'];

        self::assertNull($view->total);
        self::assertSame('EMPTY_POPULATION', $view->totalReason);
        self::assertSame('EMPTY', $view->quality);
    }

    public function testARateIsTheRatioOfSumsNotTheMeanOfMonthlyRates(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $catalogue->column('cashSavingsRate');
        self::assertNotNull($column);
        $computed = [2026 => $this->year(2026, $catalogue, [
            $this->month(2026, 1, ['cashIncome' => '1000', 'budgetSurplus' => '500', 'cashSavingsRate' => '0.5']),
            $this->month(2026, 2, ['cashIncome' => '3000', 'budgetSurplus' => '300', 'cashSavingsRate' => '0.1']),
        ])];

        $view = (new MultiYearDetailedPeriods())->detailedPeriod([$column], $computed, 2026, 2026, IncompleteMonths::EXCLUDE)['cashSavingsRate'];

        self::assertSame('0.2', $this->trim($view->total));
        self::assertSame('0.2', $this->trim($view->average));
        self::assertSame('0.1', $this->trim($view->minimum?->value));
        self::assertSame('0.5', $this->trim($view->maximum?->value));
    }

    public function testAnAccountColumnIgnoresMonthsBeforeItOpened(): void
    {
        $catalogue = new AnnualCatalogue([], [], ['a1' => new ReportAccountReference('a1', 'Livret', 'SAVINGS', true, false, '2026-02-10')]);
        $column = $catalogue->column('account:a1');
        $other = $catalogue->column('cashIncome');
        self::assertNotNull($column);
        self::assertNotNull($other);
        $months = [
            $this->month(2026, 1, ['account:a1' => '0']),
            $this->month(2026, 2, ['account:a1' => '100']),
        ];
        $detailed = new MultiYearDetailedPeriods();

        $filtered = $detailed->monthsForColumn($column, $months, $catalogue);

        self::assertCount(1, $filtered);
        self::assertSame(2, $filtered[0]->month->month);
        self::assertCount(2, $detailed->monthsForColumn($other, $months, $catalogue));
    }

    public function testByMonthKeyAndMatchingFollowTheRequestedMonths(): void
    {
        $series = AnnualColumnSeries::values('cashIncome', true, [
            $this->month(2026, 1, ['cashIncome' => '1']),
            $this->month(2026, 2, ['cashIncome' => '2']),
        ]);
        $detailed = new MultiYearDetailedPeriods();

        $indexed = $detailed->byMonthKey($series);

        self::assertSame(['2026-01', '2026-02'], array_keys($indexed));
        self::assertSame([$series[1], $series[0]], $detailed->matching([$series[1], $series[0]], $indexed));
        self::assertSame([], $detailed->matching([], $indexed));
        self::assertSame([], $detailed->byMonthKey([]));
    }

    public function testUnavailableDetailedAndExtremeEdgeCases(): void
    {
        $detailed = new MultiYearDetailedPeriods();

        self::assertNull($detailed->detailedExtreme(null));
        $mixed = $detailed->unavailableDetailed(AggregateReason::MIXED_ASSETS);
        self::assertSame('MIXED_ASSETS', $mixed->totalReason);
        self::assertSame('PARTIAL', $mixed->quality);
        self::assertNull($mixed->total);
        self::assertSame('EMPTY', $detailed->unavailableDetailed(AggregateReason::EMPTY_POPULATION)->quality);
    }

    public function testTheMeanOfNoMonthIsNullWithItsReason(): void
    {
        [$mean, $reason] = (new MultiYearDetailedPeriods())->meanForResponse([]);

        self::assertNull($mean);
        self::assertNotNull($reason);
    }

    private function trim(?string $value): ?string
    {
        if (null === $value || !str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    /** @param list<AnnualMonth> $months */
    private function year(int $year, AnnualCatalogue $catalogue, array $months): ComputedAnnualYear
    {
        return new ComputedAnnualYear($year, IncompleteMonths::EXCLUDE, $catalogue, [], $months, [], new AnnualPolicyView(AnnualPolicyView::SINGLE, 1, null, []), []);
    }

    /** @param array<string, string> $values */
    private function month(int $year, int $number, array $values, int $policy = 1): AnnualMonth
    {
        $cells = [];
        foreach ($values as $id => $value) {
            $cells[$id] = new MonthlyMetricView($value, 'EUR', null);
        }

        return new AnnualMonth(
            new CalendarMonth($year, $number),
            MonthState::COMPLETE,
            false,
            false,
            new MonthFigures(sprintf('%d-%02d', $year, $number), $policy, 'CURRENT', 0, sprintf('%d-%02d-28', $year, $number), $cells, [], [], []),
        );
    }
}
