<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application\Facade;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\Facade\ReportFigureKey;
use App\Module\Reporting\Application\Facade\ReportFigureSeries;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use PHPUnit\Framework\TestCase;

final class ReportFigureTest extends TestCase
{
    public function testAFigureKeepsItsExactValueAndAsset(): void
    {
        $figure = self::month(1, ['cashIncome' => new MonthlyMetricView('180.10', 'EUR', null)])->figure(ReportFigureKey::cashIncome());

        self::assertSame('180.10', $figure->value?->toString());
        self::assertSame('EUR', $figure->asset?->toString());
        self::assertNull($figure->reason);
        self::assertFalse($figure->noMovements);
    }

    public function testAnAbsentCellAndAMonthWithoutFiguresReadAMissingValueNeverZero(): void
    {
        $empty = new AnnualMonth(new CalendarMonth(2026, 2), MonthState::NO_DATA, false, false, null);
        foreach ([self::month(1, []), $empty] as $month) {
            $figure = $month->figure(ReportFigureKey::cashIncome());

            self::assertNull($figure->value);
            self::assertNull($figure->asset);
            self::assertSame('MISSING_MONTH_VALUE', $figure->reason);
            self::assertFalse($figure->noMovements);
        }
        self::assertNull($empty->hasTransactions());
        self::assertNull($empty->policyVersion());
        self::assertSame([], $empty->budgetExpenseCategoryIds());
    }

    public function testACellWithoutMovementSaysSo(): void
    {
        $figure = self::month(1, ['budgetExpenses' => new MonthlyMetricView(null, null, 'NO_MOVEMENTS')])->figure(ReportFigureKey::budgetExpenses());

        self::assertNull($figure->value);
        self::assertSame('NO_MOVEMENTS', $figure->reason);
        self::assertTrue($figure->noMovements);
    }

    public function testASeriesAggregatesFlowsBySumAndRatesFromTheirSources(): void
    {
        $months = [self::month(1, self::rateCells('1000', '100')), self::month(2, self::rateCells('3000', '900'))];

        $flow = ReportFigureSeries::aggregate(ReportFigureKey::cashIncome(), $months, IncompleteMonths::INCLUDE);
        $rate = ReportFigureSeries::aggregate(ReportFigureKey::cashSavingsRate(), $months, IncompleteMonths::INCLUDE);

        self::assertSame('4000', $flow->total?->toString());
        self::assertStringStartsWith('0.25', (string) $rate->total?->toString());
        self::assertCount(2, ReportFigureSeries::values(ReportFigureKey::cashIncome(), $months));
    }

    /** @return array<string, MonthlyMetricView> */
    private static function rateCells(string $income, string $surplus): array
    {
        return [
            'cashIncome' => new MonthlyMetricView($income, 'EUR', null),
            'budgetSurplus' => new MonthlyMetricView($surplus, 'EUR', null),
            'cashSavingsRate' => new MonthlyMetricView('0.9', null, null),
        ];
    }

    /** @param array<string, MonthlyMetricView> $cells */
    private static function month(int $index, array $cells): AnnualMonth
    {
        $month = new CalendarMonth(2026, $index);

        return new AnnualMonth($month, MonthState::COMPLETE, false, false, new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), $cells, [], [], []));
    }
}
