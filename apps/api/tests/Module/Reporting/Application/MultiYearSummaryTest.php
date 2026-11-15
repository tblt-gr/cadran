<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Application\ReportAccountReference;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Application\AnnualAggregateView;
use App\Module\Reporting\Application\AnnualCatalogue;
use App\Module\Reporting\Application\AnnualCellView;
use App\Module\Reporting\Application\AnnualColumn;
use App\Module\Reporting\Application\AnnualColumnSeries;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\AnnualPolicyView;
use App\Module\Reporting\Application\ComputedAnnualYear;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\MultiYearFlowsView;
use App\Module\Reporting\Application\MultiYearMetricPolicyView;
use App\Module\Reporting\Application\MultiYearSummary;
use App\Module\Reporting\Application\MultiYearYearView;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use PHPUnit\Framework\TestCase;

final class MultiYearSummaryTest extends TestCase
{
    public function testTheSummaryAveragesCompleteYearsAndKeepsTheirExtremes(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $this->column($catalogue, 'cashIncome');
        $computed = [
            2024 => $this->year(2024, $catalogue, '1000'),
            2025 => $this->year(2025, $catalogue, '3000'),
        ];

        $summary = (new MultiYearSummary())->summary([$column], [$this->yearView(2024, 'COMPLETE'), $this->yearView(2025, 'COMPLETE')], $computed);

        $cell = $summary['cashIncome'];
        self::assertSame('2000', $this->trim($cell->average));
        self::assertNull($cell->averageReason);
        self::assertSame('1000', $this->trim($cell->minimum?->value));
        self::assertSame(2024, $cell->minimum?->year);
        self::assertSame('3000', $this->trim($cell->maximum?->value));
        self::assertSame(2025, $cell->maximum?->year);
    }

    public function testPartialYearsAreOutsideTheSummary(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $this->column($catalogue, 'cashIncome');
        $computed = [2025 => $this->year(2025, $catalogue, '3000')];

        $summary = (new MultiYearSummary())->summary([$column], [$this->yearView(2025, 'PARTIAL')], $computed);

        self::assertNull($summary['cashIncome']->average);
        self::assertSame('EMPTY_POPULATION', $summary['cashIncome']->averageReason);
        self::assertNull($summary['cashIncome']->minimum);
    }

    public function testYearsUnderDifferentPoliciesAreNotAveraged(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $this->column($catalogue, 'cashIncome');
        $computed = [
            2024 => $this->year(2024, $catalogue, '1000', 1),
            2025 => $this->year(2025, $catalogue, '3000', 2),
        ];

        $cell = (new MultiYearSummary())->summaryCell($column, [$this->yearView(2024, 'COMPLETE'), $this->yearView(2025, 'COMPLETE')], $computed);

        self::assertNull($cell->average);
        self::assertSame('MIXED_METRIC_POLICIES', $cell->averageReason);
    }

    public function testAnInternallyMixedYearNeverAgreesWithAnother(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $this->column($catalogue, 'cashIncome');
        $computed = [2025 => $this->year(2025, $catalogue, '3000', null, AnnualPolicyView::MIXED)];

        $cell = (new MultiYearSummary())->summaryCell($column, [$this->yearView(2025, 'COMPLETE')], $computed);

        self::assertNull($cell->average);
        self::assertSame('MIXED_METRIC_POLICIES', $cell->averageReason);
    }

    public function testAYearBeforeTheAccountOpenedIsOutsideItsAverageAndFigureIsNotOpen(): void
    {
        $catalogue = new AnnualCatalogue([], ['a1' => 'Livret'], ['a1' => new ReportAccountReference('a1', 'Livret', 'SAVINGS', true, false, '2025-06-01')]);
        $column = $this->column($catalogue, 'account:a1');
        $summary = new MultiYearSummary();
        $before = $this->year(2024, $catalogue, '0', 1, AnnualPolicyView::SINGLE, 'account:a1');
        $after = $this->year(2025, $catalogue, '100', 1, AnnualPolicyView::SINGLE, 'account:a1');

        [$value, $reason, $asOf] = $summary->figureFor($column, $before);
        self::assertNull($value);
        self::assertSame('ACCOUNT_NOT_OPEN', $reason);
        self::assertNull($asOf);

        $cell = $summary->summaryCell($column, [$this->yearView(2024, 'COMPLETE'), $this->yearView(2025, 'COMPLETE')], [2024 => $before, 2025 => $after]);
        self::assertSame('100', $this->trim($cell->average));
        self::assertSame(2025, $cell->minimum?->year);
    }

    public function testAStockFigureCarriesItsPeriodEndMonth(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $this->column($catalogue, 'endNetWorth');
        $computed = $this->year(2025, $catalogue, '1000', 1, AnnualPolicyView::SINGLE, 'endNetWorth');

        [$value, $reason, $asOf] = (new MultiYearSummary())->figureFor($column, $computed);

        self::assertSame('1000', $this->trim($value?->toString()));
        self::assertNull($reason);
        self::assertSame('2025-12', $asOf);
    }

    public function testExtremeOfNothingIsNull(): void
    {
        $summary = new MultiYearSummary();

        self::assertNull($summary->extreme(null));
        $view = $summary->extreme([2024, DecimalValue::fromString('5')]);
        self::assertNotNull($view);
        self::assertSame(2024, $view->year);
        self::assertSame('5', $view->value);
    }

    public function testChangeNeedsTwoCompleteYearsOnTheSamePolicy(): void
    {
        $single = new AnnualPolicyView(AnnualPolicyView::SINGLE, 1, null, []);
        $other = new AnnualPolicyView(AnnualPolicyView::SINGLE, 2, null, []);
        $ten = DecimalValue::fromString('10');
        $five = DecimalValue::fromString('5');

        self::assertSame([null, null, 'PARTIAL_YEAR'], MultiYearSummary::change(ColumnKind::FLOW, $ten, null, $five, null, 'PARTIAL', 'COMPLETE', $single, $single));
        self::assertSame([null, null, 'PARTIAL_YEAR'], MultiYearSummary::change(ColumnKind::FLOW, $ten, null, null, null, 'COMPLETE', null, $single, null));
        self::assertSame([null, null, 'POLICY_MISMATCH'], MultiYearSummary::change(ColumnKind::FLOW, $ten, null, $five, null, 'COMPLETE', 'COMPLETE', $single, $other));
        self::assertSame([null, null, 'POLICY_MISMATCH'], MultiYearSummary::change(ColumnKind::FLOW, $ten, null, $five, null, 'COMPLETE', 'COMPLETE', $single, null));
    }

    public function testChangeSurfacesTheCellReasonRatherThanZero(): void
    {
        $single = new AnnualPolicyView(AnnualPolicyView::SINGLE, 1, null, []);

        self::assertSame([null, null, 'ZERO_DENOMINATOR'], MultiYearSummary::change(ColumnKind::RATE, null, 'ZERO_DENOMINATOR', DecimalValue::fromString('1'), null, 'COMPLETE', 'COMPLETE', $single, $single));
        self::assertSame([null, null, 'PARTIAL_YEAR'], MultiYearSummary::change(ColumnKind::FLOW, null, null, null, null, 'COMPLETE', 'COMPLETE', $single, $single));
    }

    public function testChangeIsAbsoluteForRatesAndRelativeToThePositiveBaseForAmounts(): void
    {
        $single = new AnnualPolicyView(AnnualPolicyView::SINGLE, 1, null, []);
        $args = static fn (ColumnKind $kind, string $value, string $previous): array => MultiYearSummary::change($kind, DecimalValue::fromString($value), null, DecimalValue::fromString($previous), null, 'COMPLETE', 'COMPLETE', $single, $single);

        [$rateChange, $rateRatio, $rateReason] = $args(ColumnKind::RATE, '0.3', '0.2');
        self::assertSame('0.1', $this->trim($rateChange));
        self::assertNull($rateRatio);
        self::assertNull($rateReason);

        [$change, $ratio, $reason] = $args(ColumnKind::FLOW, '150', '100');
        self::assertSame('50', $this->trim($change));
        self::assertSame('0.5', $this->trim($ratio));
        self::assertNull($reason);

        [$change, $ratio, $reason] = $args(ColumnKind::FLOW, '150', '0');
        self::assertSame('150', $this->trim($change));
        self::assertNull($ratio);
        self::assertSame('ZERO_BASE', $reason);

        [$change, $ratio, $reason] = $args(ColumnKind::FLOW, '-50', '-100');
        self::assertSame('50', $this->trim($change));
        self::assertNull($ratio);
        self::assertSame('NEGATIVE_BASE', $reason);
    }

    private function trim(?string $value): ?string
    {
        if (null === $value || !str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    private function column(AnnualCatalogue $catalogue, string $id): AnnualColumn
    {
        $column = $catalogue->column($id);
        self::assertNotNull($column);

        return $column;
    }

    private function yearView(int $year, string $state): MultiYearYearView
    {
        $cell = new AnnualCellView(null, null);

        return new MultiYearYearView($year, $state, 12, 0, 12, new MultiYearMetricPolicyView(AnnualPolicyView::SINGLE, 1), [], new MultiYearFlowsView('EUR', $cell, $cell, $cell));
    }

    private function year(int $year, AnnualCatalogue $catalogue, string $amount, ?int $version = 1, string $state = AnnualPolicyView::SINGLE, string $columnId = 'cashIncome'): ComputedAnnualYear
    {
        $months = [];
        foreach (range(1, 12) as $number) {
            $months[] = new AnnualMonth(
                new CalendarMonth($year, $number),
                MonthState::COMPLETE,
                false,
                false,
                new MonthFigures(sprintf('%d-%02d', $year, $number), $version ?? 1, 'CURRENT', 0, sprintf('%d-%02d-28', $year, $number), [$columnId => new MonthlyMetricView(1 === $number || 'cashIncome' !== $columnId ? $amount : '0', 'EUR', null)], [], [], []),
            );
        }
        $column = $this->column($catalogue, $columnId);
        $aggregate = AnnualColumnSeries::aggregate($column, $months, IncompleteMonths::EXCLUDE);

        return new ComputedAnnualYear(
            $year,
            IncompleteMonths::EXCLUDE,
            $catalogue,
            [$column],
            $months,
            [],
            new AnnualPolicyView($state, $version, null, []),
            [$columnId => [AnnualAggregateView::of($aggregate, null, null), $aggregate]],
        );
    }
}
