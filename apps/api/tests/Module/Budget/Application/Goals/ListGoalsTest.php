<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Application\ReadAccountReference;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Budget\Application\Goals\ListGoals;
use App\Module\Budget\Application\Goals\PresentGoal;
use App\Module\Budget\Application\Goals\ReadGoalStatus;
use App\Module\Budget\Application\Goals\ReadReferenceIncome;
use App\Module\Budget\Application\Goals\ResolveGoalScope;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalKind;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Transactions\Application\MonthlyTransferPairFact;
use App\Module\Transactions\Application\MonthlyTransferPairReader;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ListGoalsTest extends TestCase
{
    private const string SAVINGS_ID = '00000000-0000-7000-8000-000000000101';
    private const string SPENDING_ID = '00000000-0000-7000-8000-000000000111';
    private const string ROLLING_ID = '00000000-0000-7000-8000-000000000121';
    private const string LATE_ROLLING_ID = '00000000-0000-7000-8000-000000000122';
    private const string CATEGORY_ID = '00000000-0000-7000-8000-000000000201';
    private const string ACCOUNT_A_GOAL_ID = '00000000-0000-7000-8000-000000000131';
    private const string ACCOUNT_B_GOAL_ID = '00000000-0000-7000-8000-000000000141';
    private const string ACCOUNT_A = '00000000-0000-7000-8000-000000000301';
    private const string ACCOUNT_B = '00000000-0000-7000-8000-000000000302';

    public function testListReadsReportingMonthsCategoriesAndScopesOncePerRequest(): void
    {
        // Every month 2025-09..2026-09: income 2500.00, savings 100.00, category spending 50.00.
        // Savings to September 900.00 vs 900.00 expected (1200 linear): ahead. Spending 450.00 vs 900.00: on track.
        // Rolling 0.20 x 2500 = 500 target against 100.00 saved in September: gap -400, missed.
        $source = new CountingGoalMonthSource(self::months());
        $categories = $this->createMock(CategoryRepository::class);
        $categories->expects(self::once())->method('list')->willReturn([self::category()]);
        $categories->expects(self::once())->method('find')->willReturn(self::category());

        $items = $this->list($source, $categories)(2026, 'active')->items;

        self::assertSame(1, $source->calls);
        self::assertCount(3, $items);
        [$savings, $spending, $rolling] = array_map(static fn ($item) => $item->status ?? self::fail('A listed goal carries its status.'), $items);
        self::assertSame('900.00', $savings->achieved);
        self::assertSame('AHEAD', $savings->status);
        self::assertSame('450.00', $spending->achieved);
        self::assertSame('ON_TRACK', $spending->status);
        self::assertSame('500.000000000000000000000000', $rolling->target);
        self::assertSame('-400.000000000000000000000000', $rolling->gap);
        self::assertSame('MISSED', $rolling->status);
        self::assertSame('2500.000000000000000000000000', $rolling->referenceIncome?->value?->toString());
    }

    public function testListReadsTransferPairsOncePerMonthForAllAccountGoals(): void
    {
        // January: 250.125 moved from B to A. February: 50.000000000000000000000001 moved back from A to B.
        // Through September, A nets +200.124999999999999999999999 and B the exact opposite, both
        // behind the 900.00 expected of a 1200.00 linear minimum. Nine months are read, not eighteen.
        $transfers = new CountingTransferPairReader([
            '2026-01' => [self::pair('00000000-0000-7000-8000-000000000401', self::ACCOUNT_B, self::ACCOUNT_A, '250.125', '2026-01-15')],
            '2026-02' => [self::pair('00000000-0000-7000-8000-000000000402', self::ACCOUNT_A, self::ACCOUNT_B, '50.000000000000000000000001', '2026-02-03')],
        ]);

        $items = $this->accountList($transfers)(2026, 'active')->items;

        self::assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'], $transfers->months);
        self::assertSame([WorkspaceFixture::OWN_WORKSPACE], array_values(array_unique($transfers->workspaces)));
        [$savings, $accountA, $accountB] = array_map(static fn ($item) => $item->status ?? self::fail('A listed goal carries its status.'), $items);
        self::assertSame('AHEAD', $savings->status);
        self::assertSame('200.124999999999999999999999', $accountA->achieved);
        self::assertSame('BEHIND', $accountA->status);
        self::assertSame('-200.124999999999999999999999', $accountB->achieved);
        self::assertSame('BEHIND', $accountB->status);
    }

    public function testAnOversizedTransferMonthLeavesOnlyAccountGoalsNotCalculable(): void
    {
        // March holds one pair more than a monthly read accepts: the account goals lose their
        // total instead of failing the whole list, and the workspace savings goal is still computed.
        $oversized = [];
        for ($index = 0; $index <= ReadMonthlyTransactionFacts::MAX_TRANSACTIONS; ++$index) {
            $oversized[] = self::pair(sprintf('00000000-0000-7000-8000-%012d', 1000 + $index), self::ACCOUNT_B, self::ACCOUNT_A, '1.00', '2026-03-10');
        }
        $transfers = new CountingTransferPairReader(['2026-03' => $oversized]);

        $items = $this->accountList($transfers)(2026, 'active')->items;

        self::assertCount(9, $transfers->months);
        [$savings, $accountA, $accountB] = array_map(static fn ($item) => $item->status ?? self::fail('A listed goal carries its status.'), $items);
        self::assertSame('AHEAD', $savings->status);
        foreach ([$accountA, $accountB] as $status) {
            self::assertSame('NOT_CALCULABLE', $status->status);
            self::assertSame('MISSING_MONTH_VALUE', $status->reason);
            self::assertNull($status->achieved);
            self::assertNull($status->gap);
            self::assertSame('0', $status->months[1]->value);
            self::assertNull($status->months[2]->value);
        }
    }

    public function testAGoalStartingAfterTheChosenMonthListsAsNotStartedInsteadOfFailing(): void
    {
        // The rolling goal only takes effect in 2026-08: June has no version of it yet.
        $items = $this->list(new CountingGoalMonthSource(self::months()), $this->createStub(CategoryRepository::class), [
            self::goal(self::LATE_ROLLING_ID, GoalKind::ROLLING_RATIO, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE),
        ])(2026, 'active', '2026-06')->items;

        self::assertCount(1, $items);
        $status = $items[0]->status ?? self::fail('A listed goal carries its status.');
        self::assertSame('2026-06', $status->month);
        self::assertSame('NOT_CALCULABLE', $status->status);
        self::assertSame('GOAL_NOT_STARTED', $status->reason);
        self::assertSame('2026-08', $items[0]->currentVersion->effectiveFrom);
    }

    public function testAFutureYearListsGoalsAsNotCalculableAtItsJanuaryInsteadOfTodayMonth(): void
    {
        // Today is 2026-09. The rolling goal (0.20 from 2026-01) is still in effect in 2027, but
        // 2027 has no real month yet: the card must not show the September 2026 figures
        // (target 500, gap -400, missed) under a 2027 period.
        $source = new CountingGoalMonthSource(self::months());

        $items = $this->list($source, $this->createStub(CategoryRepository::class), [
            self::goal(self::ROLLING_ID, GoalKind::ROLLING_RATIO, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE),
        ])(2027, 'active')->items;

        self::assertCount(1, $items);
        $status = $items[0]->status ?? self::fail('A listed goal carries its status.');
        self::assertSame('2027-01', $status->month);
        self::assertSame('NOT_CALCULABLE', $status->status);
        self::assertSame('FUTURE_MONTH', $status->reason);
        self::assertNull($status->target);
        self::assertNull($status->actual);
        self::assertNull($status->gap);
        self::assertNull($status->referenceIncome);
        self::assertSame('0.20', $status->ratio);
        self::assertSame(0, $source->calls);
    }

    private function accountList(MonthlyTransferPairReader $transfers): ListGoals
    {
        $categories = $this->createStub(CategoryRepository::class);
        $accounts = new InMemoryAccountRepository(
            AccountFixture::account(id: self::ACCOUNT_A),
            AccountFixture::account(id: self::ACCOUNT_B),
        );

        return $this->list(new CountingGoalMonthSource(self::months()), $categories, [
            self::goal(self::SAVINGS_ID, GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE),
            self::goal(self::ACCOUNT_A_GOAL_ID, GoalKind::ANNUAL, GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalScopeType::ACCOUNT, self::ACCOUNT_A),
            self::goal(self::ACCOUNT_B_GOAL_ID, GoalKind::ANNUAL, GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalScopeType::ACCOUNT, self::ACCOUNT_B),
        ], $transfers, $accounts);
    }

    /** @param ?list<Goal> $listed */
    private function list(GoalMonthSource $source, CategoryRepository $categories, ?array $listed = null, ?MonthlyTransferPairReader $transfers = null, ?InMemoryAccountRepository $accounts = null): ListGoals
    {
        $caller = $this->createStub(CallerWorkspace::class);
        $caller->method('resolve')->willReturn(WorkspaceFixture::own());
        $goals = $this->createStub(GoalRepository::class);
        $goals->method('listForYear')->willReturn($listed ?? [
            self::goal(self::SAVINGS_ID, GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE),
            self::goal(self::SPENDING_ID, GoalKind::ANNUAL, GoalMetric::CATEGORY_SPENDING, GoalScopeType::CATEGORY, self::CATEGORY_ID),
            self::goal(self::ROLLING_ID, GoalKind::ROLLING_RATIO, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE),
        ]);
        $versions = $this->createStub(GoalVersionRepository::class);
        $versions->method('listByGoal')->willReturnCallback(static fn (WorkspaceScope $workspace, string $goalId): array => [self::version($goalId)]);
        $scope = new ResolveGoalScope(new ReadCategoryReference($categories), new ReadAccountReference($accounts ?? new InMemoryAccountRepository()));
        $reference = new ReadReferenceIncome($source);
        $calendar = self::calendar();
        $status = new ReadGoalStatus(
            $caller,
            $goals,
            $versions,
            $source,
            new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($transfers ?? new EmptyTransferPairReader())),
            $calendar,
            $scope,
            new ReadBudgetCategoryFacts($categories),
        );

        return new ListGoals($caller, $goals, $versions, new PresentGoal($scope, $reference, $calendar), $status, $scope, $calendar);
    }

    private static function version(string $goalId): GoalVersion
    {
        $created = new \DateTimeImmutable('2026-01-01');
        if (self::LATE_ROLLING_ID === $goalId) {
            return GoalVersion::rolling($goalId, WorkspaceFixture::own(), $goalId, 1, '2026-08', DecimalValue::fromString('0.20'), $created, WorkspaceFixture::OWNER_ID);
        }
        if (self::ROLLING_ID === $goalId) {
            return GoalVersion::rolling($goalId, WorkspaceFixture::own(), $goalId, 1, '2026-01', DecimalValue::fromString('0.20'), $created, WorkspaceFixture::OWNER_ID);
        }
        $target = DecimalValue::fromString('1200.00');

        return GoalVersion::annual($goalId, WorkspaceFixture::own(), $goalId, 1, '2026-01', 2026, $target, GoalSchedule::linear($target, 2), $created, WorkspaceFixture::OWNER_ID);
    }

    private static function goal(string $id, GoalKind $kind, GoalMetric $metric, GoalScopeType $scope, ?string $scopeRef = null): Goal
    {
        return new Goal($id, WorkspaceFixture::own(), 'Goal', $kind, $metric, $scope, $scopeRef, AssetCode::fromString('EUR'), GoalState::ACTIVE, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
    }

    private static function pair(string $transferId, string $source, string $target, string $amount, string $bookedOn): MonthlyTransferPairFact
    {
        $asset = AssetCode::fromString('EUR');

        return new MonthlyTransferPairFact($transferId, null, null, $source, $target, DecimalValue::fromString('-'.$amount), DecimalValue::fromString($amount), $asset, $asset, 'BOOKED', 'BOOKED', $bookedOn, $bookedOn, false);
    }

    private static function category(): Category
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Category(self::CATEGORY_ID, WorkspaceFixture::own(), CategoryType::EXPENSE, 'Groceries', null, null, null, [], true, 0, 1, 1, $now, $now);
    }

    /** @return list<AnnualMonth> */
    private static function months(): array
    {
        $months = [];
        for ($ordinal = 0; $ordinal < 13; ++$ordinal) {
            $date = (new \DateTimeImmutable('2025-09-01'))->modify(sprintf('+%d months', $ordinal));
            $month = new CalendarMonth((int) $date->format('Y'), (int) $date->format('n'));
            $cells = [
                'cashIncome' => new MonthlyMetricView('2500.00', 'EUR', null),
                'netSavingsTransfers' => new MonthlyMetricView('100.00', 'EUR', null),
                'budgetExpenses' => new MonthlyMetricView('50.00', 'EUR', null),
                'category:'.self::CATEGORY_ID => new MonthlyMetricView('50.00', 'EUR', null),
            ];
            $state = 12 === $ordinal ? MonthState::PROVISIONAL : MonthState::COMPLETE;
            $months[] = new AnnualMonth($month, $state, false, false, new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), $cells, [self::CATEGORY_ID], [], []));
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

        return new WorkspaceCalendar(new MockClock('2026-09-24T12:00:00+02:00'), $context, $timezones);
    }
}

final class CountingGoalMonthSource implements GoalMonthSource
{
    public int $calls = 0;

    /** @param list<AnnualMonth> $months */
    public function __construct(private readonly array $months)
    {
    }

    public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end): array
    {
        ++$this->calls;

        return array_values(array_filter($this->months, static fn (AnnualMonth $month): bool => $month->month->key() >= $start->key() && $month->month->key() <= $end->key()));
    }
}

final class CountingTransferPairReader implements MonthlyTransferPairReader
{
    /** @var list<string> */
    public array $months = [];

    /** @var list<string> */
    public array $workspaces = [];

    /** @param array<string, list<MonthlyTransferPairFact>> $pairsByMonth */
    public function __construct(private readonly array $pairsByMonth)
    {
    }

    public function read(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        $this->months[] = $from->format('Y-m');
        $this->workspaces[] = $workspace->id;

        return $workspace->equals(WorkspaceFixture::own()) ? array_slice($this->pairsByMonth[$from->format('Y-m')] ?? [], 0, $limit) : [];
    }
}
