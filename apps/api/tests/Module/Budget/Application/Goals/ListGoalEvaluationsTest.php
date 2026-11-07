<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Application\ReadAccountReference;
use App\Module\Accounts\Domain\CalendarMonth;
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

    /** @return array{0: string, 1: ?string, 2: ?string, 3: string, 4: list<string>} */
    private static function row(GoalEvaluationView $item): array
    {
        $target = $item->target;
        $figure = null === $target ? null : ($target->isRate() ? $target->ratio : $target->value);

        return [$item->goalId, $figure, $item->gap, $item->status, $item->overrides];
    }

    /** @param list<GoalVersion> $extraVersions */
    private function evaluate(array $extraVersions = [], ?string $withNoDataMonth = null, ?WorkspaceScope $callerWorkspace = null, bool $withYearlyRate = false): ListGoalEvaluations
    {
        $own = WorkspaceFixture::own();
        $caller = $this->createStub(CallerWorkspace::class);
        $caller->method('resolve')->willReturn($callerWorkspace ?? $own);
        $goals = $this->createStub(GoalRepository::class);
        $all = self::goals($withYearlyRate);
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
        $source = new class(self::months($withNoDataMonth)) implements GoalMonthSource {
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
