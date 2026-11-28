<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application\Facade;

use App\Module\Reporting\Application\Facade\ReportFigureKey;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\MonthlyRecapCategoryView;
use App\Tests\Module\Reporting\Application\Double\MonthlyProjectionFixture;
use PHPUnit\Framework\TestCase;

/** The contract between the facade and the report cells: a renamed cell fails here, not as a missing goal value. */
final class ReportFigureKeyTest extends TestCase
{
    public function testTheCellIdsStayThoseStoredSnapshotsHold(): void
    {
        // Key and cell move together, so only the literal catches a rename that would orphan closed months.
        self::assertSame(
            ['cashIncome', 'budgetExpenses', 'budgetSurplus', 'netSavingsTransfers', 'cashSavingsRate', 'netSavingsRate', 'axis:ESSENTIAL', 'ownCategory:c1'],
            array_map(static fn (ReportFigureKey $key): string => $key->id, [
                ReportFigureKey::cashIncome(), ReportFigureKey::budgetExpenses(), ReportFigureKey::budgetSurplus(),
                ReportFigureKey::netSavingsTransfers(), ReportFigureKey::cashSavingsRate(), ReportFigureKey::netSavingsRate(),
                ReportFigureKey::axisSpending('ESSENTIAL'), ReportFigureKey::ownCategorySpending('c1'),
            ]),
        );
    }

    public function testEveryKeyResolvesToItsCellOfALiveProjection(): void
    {
        $axis = new MonthlyMetricView('40', 'EUR', null);
        $own = new MonthlyMetricView('12.50', 'EUR', null);
        $projection = MonthlyProjectionFixture::view(
            '2025-03',
            categoryMetrics: [new MonthlyRecapCategoryView('c1', 'Food', 'EXPENSE', $own)],
            categoryRollups: [new MonthlyRecapCategoryView('c1', 'Food', 'EXPENSE', new MonthlyMetricView('99', 'EUR', null))],
            expensesByAxis: ['ESSENTIAL' => $axis],
        );
        $keys = [
            'cashIncome' => [ReportFigureKey::cashIncome(), $projection->cashIncome],
            'budgetExpenses' => [ReportFigureKey::budgetExpenses(), $projection->budgetExpenses],
            'budgetSurplus' => [ReportFigureKey::budgetSurplus(), $projection->budgetSurplus],
            'netSavingsTransfers' => [ReportFigureKey::netSavingsTransfers(), $projection->netSavingsTransfers],
            'cashSavingsRate' => [ReportFigureKey::cashSavingsRate(), $projection->cashSavingsRate],
            'netSavingsRate' => [ReportFigureKey::netSavingsRate(), $projection->netSavingsRate],
            'axisSpending' => [ReportFigureKey::axisSpending('ESSENTIAL'), $axis],
            'ownCategorySpending' => [ReportFigureKey::ownCategorySpending('c1'), $own],
        ];

        $cells = MonthFigures::fromProjection($projection)->cells;

        foreach ($keys as $name => [$key, $metric]) {
            self::assertArrayHasKey($key->id, $cells, $name);
            self::assertSame($metric, $cells[$key->id], $name);
        }

        // A constructor added without a line above would be outside the contract.
        $constructors = array_filter(
            (new \ReflectionClass(ReportFigureKey::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => $method->isStatic(),
        );
        self::assertEqualsCanonicalizing(array_keys($keys), array_map(static fn (\ReflectionMethod $method): string => $method->getName(), array_values($constructors)));
    }
}
