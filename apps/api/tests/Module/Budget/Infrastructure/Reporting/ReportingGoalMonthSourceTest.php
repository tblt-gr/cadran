<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Infrastructure\Reporting;

use App\Module\Accounts\Application\FirstNetWorthDataDayReader;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\ReadReferenceIncome;
use App\Module\Budget\Infrastructure\Reporting\ReportingGoalMonthSource;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\LoadAnnualMonths;
use App\Module\Reporting\Application\MetricPolicyReference;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\MonthlyProjectionView;
use App\Module\Reporting\Application\MonthlyProjector;
use App\Module\Reporting\Application\ReadFirstDataMonth;
use App\Module\Reporting\Application\RegroupMonthFigures;
use App\Module\Reporting\Application\WorkspaceToday;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Tests\Module\Reporting\Application\Double\FixedGroupTree;
use App\Tests\Module\Reporting\Application\Double\InMemoryMonthSnapshotStore;
use App\Tests\Module\Reporting\Application\Double\InMemoryPeriodClosureRepository;
use App\Tests\Module\Reporting\Application\Double\MonthlyProjectionFixture;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The real month source behind rolling-goal reference income: months before
 * the first data month are excluded, a recorded zero-income month counts, and
 * a complete month with no income value does not.
 */
final class ReportingGoalMonthSourceTest extends TestCase
{
    public function testReferenceIncomeAcrossTwoYearsCountsTheRecordedZeroAndExcludesMonthsBeforeFirstData(): void
    {
        $incomes = [
            '2025-11' => '5000.00',
            '2025-12' => '2500.00',
            '2026-01' => '2500.00',
            '2026-02' => '2500.00',
            '2026-03' => '2500.00',
            '2026-04' => '2500.00',
            '2026-05' => '2500.00',
            '2026-06' => '2500.00',
            '2026-07' => '0.00',
            '2026-08' => null,
        ];
        $source = $this->source($incomes);

        $months = $source->between(WorkspaceFixture::own(), new CalendarMonth(2025, 9), new CalendarMonth(2026, 8));
        self::assertCount(12, $months);
        self::assertSame(MonthState::NO_DATA, $months[0]->state);
        self::assertSame(MonthState::NO_DATA, $months[1]->state);
        self::assertSame(MonthState::COMPLETE, $months[2]->state);

        $result = (new ReadReferenceIncome($source))(WorkspaceFixture::own(), new CalendarMonth(2026, 9));

        // 5000 + 7 x 2500 + 0 = 22500 over 9 usable months.
        self::assertSame('2500.000000000000000000000000', $result->value?->toString());
        self::assertSame(9, $result->usableMonths);
        self::assertSame('2025-09', $result->windowStart);
        self::assertSame('2026-08', $result->windowEnd);
        self::assertNull($result->reason);
        $excludedMonths = array_column($result->excluded, 'month');
        self::assertContains('2025-09', $excludedMonths);
        self::assertContains('2025-10', $excludedMonths);
        self::assertContains('2026-08', $excludedMonths);
    }

    /** @param array<string, string|null> $incomes month key => cash income, null for a complete month without value */
    private function source(array $incomes): ReportingGoalMonthSource
    {
        $first = $this->createStub(FirstNetWorthDataDayReader::class);
        $first->method('onOrBefore')->willReturn(new \DateTimeImmutable('2025-11-03'));
        $timezones = $this->createStub(WorkspaceTimezoneReader::class);
        $timezones->method('timezone')->willReturn('UTC');

        $load = new LoadAnnualMonths(
            new FixedIncomeProjector($incomes),
            new InMemoryPeriodClosureRepository(),
            new InMemoryMonthSnapshotStore(),
            $this->calendar(),
            new RegroupMonthFigures(new FixedGroupTree()),
        );

        return new ReportingGoalMonthSource($load, new ReadFirstDataMonth($first, new WorkspaceToday(new MockClock('2026-09-14T09:12:00+00:00'), $timezones)));
    }

    private function calendar(): WorkspaceCalendar
    {
        $caller = new readonly class implements CallerWorkspaceContext {
            public function resolveContext(): WorkspaceContext
            {
                return new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, true);
            }
        };
        $timezones = $this->createStub(WorkspaceTimezoneReader::class);
        $timezones->method('timezone')->willReturn('UTC');

        return new WorkspaceCalendar(new MockClock('2026-09-14T09:12:00+00:00'), $caller, $timezones);
    }
}

final readonly class FixedIncomeProjector implements MonthlyProjector
{
    /** @param array<string, string|null> $incomes */
    public function __construct(private array $incomes)
    {
    }

    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month, \DateTimeImmutable $today): MonthlyProjectionView
    {
        $income = array_key_exists($month->key(), $this->incomes) ? $this->incomes[$month->key()] : '2500.00';
        if (null === $income) {
            $view = MonthlyProjectionFixture::view($month->key());
            $none = new MonthlyMetricView(null, null, 'NO_ACCOUNT');

            return new MonthlyProjectionView(
                $view->month, $view->periodStart, $view->periodEnd, $view->netWorthComparedOn, $view->netWorthAsOf,
                $view->provisional, $view->state, $view->quality, $view->pendingCount,
                $none, $view->nonCashBenefits, $view->benefitSpending, $view->budgetExpenses, $view->uncategorizedExpenses,
                $view->budgetSurplus, $view->savingsTransfers, $view->cashSavingsRate, $view->savingsInflows,
                $view->savingsWithdrawals, $view->netSavingsTransfers, $view->netSavingsRate, $view->beginningNetWorth,
                $view->endNetWorth, $view->netWorthDelta, $view->beginningNetWorthState, $view->accounts,
                $view->reconciliationStatus, $view->expensesByAxis, $view->categoryMetrics, $view->netWorth,
                new MetricPolicyReference(1, 'Définition de trésorerie'),
            );
        }

        return MonthlyProjectionFixture::view($month->key(), $income);
    }
}
