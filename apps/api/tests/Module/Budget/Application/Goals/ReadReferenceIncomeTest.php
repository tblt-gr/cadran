<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Budget\Application\Goals\ReadReferenceIncome;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use PHPUnit\Framework\TestCase;

final class ReadReferenceIncomeTest extends TestCase
{
    public function testTenUsableMonthsIncludingRecordedZeroAverageToTwoThousandFiveHundred(): void
    {
        $values = ['0.00', '2500.00', '2500.00', '2500.00', '2500.00', '2500.00', '2500.00', '2500.00', '2500.00', '5000.00'];
        $months = [];
        foreach ($values as $index => $value) {
            $months[] = $this->month(new CalendarMonth(2025 + intdiv(8 + $index, 12), (($index + 8) % 12) + 1), MonthState::COMPLETE, $value);
        }
        $months[] = $this->month(new CalendarMonth(2026, 7), MonthState::NO_DATA, null);
        $months[] = $this->month(new CalendarMonth(2026, 8), MonthState::COMPLETE, null);

        $result = (new ReadReferenceIncome(new FixedGoalMonthSource($months)))(
            WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            new CalendarMonth(2026, 9),
        );

        self::assertSame('2500.000000000000000000000000', $result->value?->toString());
        self::assertSame(10, $result->usableMonths);
        self::assertSame('2025-09', $result->windowStart);
        self::assertSame('2026-08', $result->windowEnd);
        self::assertSame('750.000000000000000000000000', ExactDecimal::multiplyForResponse($result->value ?? self::fail('Expected a reference income.'), DecimalValue::fromString('0.30')));
    }

    public function testNoUsableMonthIsExplicitlyNonCalculable(): void
    {
        $result = (new ReadReferenceIncome(new FixedGoalMonthSource([])))(
            WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            new CalendarMonth(2026, 9),
        );

        self::assertNull($result->value);
        self::assertSame('NO_REFERENCE_INCOME', $result->reason);
        self::assertSame(0, $result->usableMonths);
    }

    public function testReferenceIncomeSumMayExceedTheStorageIntegerWidthBeforeAveraging(): void
    {
        $maximum = '99999999999999999999999999';
        $months = [
            $this->month(new CalendarMonth(2026, 7), MonthState::COMPLETE, $maximum),
            $this->month(new CalendarMonth(2026, 8), MonthState::COMPLETE, $maximum),
        ];

        $result = (new ReadReferenceIncome(new FixedGoalMonthSource($months)))(
            WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            new CalendarMonth(2026, 9),
        );

        self::assertSame($maximum.'.000000000000000000000000', $result->value?->toString());
        self::assertSame(2, $result->usableMonths);
    }

    private function month(CalendarMonth $month, MonthState $state, ?string $cashIncome): AnnualMonth
    {
        $figures = null;
        if (MonthState::NO_DATA !== $state) {
            $figures = new MonthFigures($month->key(), 1, 'COMPLETE', 0, $month->lastDay()->format('Y-m-d'), [
                'cashIncome' => new MonthlyMetricView($cashIncome, 'EUR', null === $cashIncome ? 'NO_ACCOUNT' : null),
            ], [], [], []);
        }

        return new AnnualMonth($month, $state, false, false, $figures);
    }
}

final readonly class FixedGoalMonthSource implements GoalMonthSource
{
    /** @param list<AnnualMonth> $months */
    public function __construct(private array $months)
    {
    }

    public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end): array
    {
        return $this->months;
    }
}
