<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Reporting\Application\AnnualCatalogue;
use App\Module\Reporting\Application\AnnualColumnSeries;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use PHPUnit\Framework\TestCase;

final class AnnualColumnSeriesTest extends TestCase
{
    public function testARateAggregateDividesTheSumsAndNeverAddsTheMonthlyRates(): void
    {
        // January 1000 income, 500 surplus (0.5); February 3000 income, 300 surplus (0.1).
        // Rate of the year = 800 / 4000 = 0.2, not (0.5 + 0.1) or their mean 0.3.
        $months = [
            $this->month(1, ['cashIncome' => '1000', 'budgetSurplus' => '500', 'cashSavingsRate' => '0.5']),
            $this->month(2, ['cashIncome' => '3000', 'budgetSurplus' => '300', 'cashSavingsRate' => '0.1']),
        ];
        $column = (new AnnualCatalogue([], [], []))->column('cashSavingsRate');
        self::assertNotNull($column);

        $aggregate = AnnualColumnSeries::aggregate($column, $months, IncompleteMonths::EXCLUDE);

        self::assertSame('0.2', rtrim(rtrim((string) $aggregate->total?->toString(), '0'), '.') ?: '0');
        self::assertNull(AnnualColumnSeries::assetCode($column, $months));
    }

    public function testAFlowTotalEqualsTheSumOfItsMonthlyCellsToTheLastDigit(): void
    {
        $months = [
            $this->month(1, ['cashIncome' => '0.1']),
            $this->month(2, ['cashIncome' => '0.2']),
            $this->month(3, ['cashIncome' => '12345678901234567890123456.123456789012345678901234']),
        ];
        $column = (new AnnualCatalogue([], [], []))->column('cashIncome');
        self::assertNotNull($column);

        $aggregate = AnnualColumnSeries::aggregate($column, $months, IncompleteMonths::EXCLUDE);

        self::assertSame('12345678901234567890123456.423456789012345678901234', $aggregate->total?->toString());
        self::assertSame('EUR', AnnualColumnSeries::assetCode($column, $months));
    }

    public function testAnUnresolvedReferenceGivesNullCellsWithItsReasonAndNoAggregate(): void
    {
        $catalogue = new AnnualCatalogue([], [], []);
        $column = $catalogue->column('category:00000000-0000-7000-8000-0000000000c9');
        self::assertNotNull($column);
        self::assertFalse($column->known);
        $months = [$this->month(1, ['cashIncome' => '1000'])];

        $cell = AnnualColumnSeries::cell($column, $months[0]);
        $aggregate = AnnualColumnSeries::aggregate($column, $months, IncompleteMonths::EXCLUDE);

        self::assertNull($cell->value);
        self::assertSame('UNKNOWN_REFERENCE', $cell->reason);
        self::assertNull($aggregate->total);
        self::assertFalse($catalogue->has('category:00000000-0000-7000-8000-0000000000c9'));
    }

    public function testAMonthUnderAnotherPolicyMakesEveryAggregateNonCalculable(): void
    {
        $months = [
            $this->month(1, ['cashIncome' => '1000'], 1),
            $this->month(2, ['cashIncome' => '1000'], 2),
        ];
        $column = (new AnnualCatalogue([], [], []))->column('cashIncome');
        self::assertNotNull($column);

        $aggregate = AnnualColumnSeries::aggregate($column, $months, IncompleteMonths::EXCLUDE);

        self::assertNull($aggregate->total);
        self::assertSame('MIXED_METRIC_POLICIES', $aggregate->totalReason?->value);
    }

    public function testAMonthWithoutMovementStaysNullInItsCellAndCountsAsZeroInTheAggregates(): void
    {
        $months = [];
        foreach (range(1, 12) as $number) {
            $months[] = new AnnualMonth(new CalendarMonth(2026, $number), MonthState::COMPLETE, false, false, new MonthFigures(
                sprintf('2026-%02d', $number), 1, 'CURRENT', 0, sprintf('2026-%02d-28', $number),
                ['category:c1' => in_array($number, [7, 8], true) ? new MonthlyMetricView('600', 'EUR', null) : new MonthlyMetricView(null, null, 'NO_MOVEMENTS')],
                [], [],
            ));
        }
        $column = (new AnnualCatalogue(['c1' => 'Vacances'], [], []))->column('category:c1');
        self::assertNotNull($column);

        $aggregate = AnnualColumnSeries::aggregate($column, $months, IncompleteMonths::EXCLUDE);

        self::assertSame('1200', $aggregate->total?->toString());
        self::assertNull(AnnualColumnSeries::cell($column, $months[0])->value);
        self::assertSame('NO_MOVEMENTS', AnnualColumnSeries::cell($column, $months[0])->reason);
        self::assertCount(12, $aggregate->countedMonths);
        self::assertSame('100.000000000000000000000000', $aggregate->average?->toString());
    }

    public function testAFutureMonthCellIsNullWithItsState(): void
    {
        $column = (new AnnualCatalogue([], [], []))->column('cashIncome');
        self::assertNotNull($column);

        $cell = AnnualColumnSeries::cell($column, new AnnualMonth(new CalendarMonth(2026, 12), MonthState::FUTURE, false, false, null));

        self::assertNull($cell->value);
        self::assertSame('FUTURE', $cell->reason);
    }

    /** @param array<string, string> $values */
    private function month(int $number, array $values, int $policy = 1): AnnualMonth
    {
        $cells = [];
        foreach ($values as $id => $value) {
            $cells[$id] = new MonthlyMetricView($value, 'EUR', null);
        }

        return new AnnualMonth(
            new CalendarMonth(2026, $number),
            MonthState::COMPLETE,
            false,
            false,
            new MonthFigures(sprintf('2026-%02d', $number), $policy, 'CURRENT', 0, sprintf('2026-%02d-28', $number), $cells, [], []),
        );
    }
}
