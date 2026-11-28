<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalMetricReader;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Budget\Application\Goals\GoalStatusInputs;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalPolicyState;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Categories\Application\BudgetCategoryFact;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Transactions\Application\MonthlyTransferPairReader;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class GoalMetricReaderTest extends TestCase
{
    private const string GOAL_ID = '00000000-0000-7000-8000-000000000101';

    public function testInputsOfNoGoalLoadNothing(): void
    {
        $inputs = $this->reader([])->inputsBetween(WorkspaceFixture::own(), new CalendarMonth(2026, 1), new CalendarMonth(2026, 3), []);

        self::assertSame([], $inputs->months);
        self::assertNull($inputs->categoryFacts);
        self::assertSame([], $inputs->accountTransfers);
    }

    public function testInputsIndexTheLoadedMonthsByKeyAndKeepNoFactsWithoutCategoryGoal(): void
    {
        $months = [self::month(2026, 1, MonthState::COMPLETE, 'cashIncome', '10.00'), self::month(2026, 2, MonthState::COMPLETE, 'cashIncome', '20.00')];
        $goal = self::goal(GoalPeriodicity::YEARLY, GoalMetric::TOTAL_INCOME);

        $inputs = $this->reader($months)->inputs(WorkspaceFixture::own(), new CalendarMonth(2026, 2), [$goal]);

        self::assertSame(['2026-01', '2026-02'], array_keys($inputs->months));
        self::assertNull($inputs->categoryFacts);
    }

    public function testMonthlyGoalOpensTheWindowBeforeJanuary(): void
    {
        $months = [self::month(2025, 6, MonthState::COMPLETE, 'cashIncome', '10.00'), self::month(2026, 2, MonthState::COMPLETE, 'cashIncome', '20.00')];
        $goal = self::goal(GoalPeriodicity::MONTHLY, GoalMetric::TOTAL_INCOME);

        $inputs = $this->reader($months)->inputs(WorkspaceFixture::own(), new CalendarMonth(2026, 2), [$goal]);

        self::assertArrayHasKey('2025-06', $inputs->months);
    }

    public function testAccountTransfersAreSkippedWithoutAccountGoal(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, GoalMetric::TOTAL_INCOME);

        $inputs = $this->reader([])->inputs(WorkspaceFixture::own(), new CalendarMonth(2026, 2), [$goal]);

        self::assertSame([], $inputs->accountTransfers);
    }

    public function testMetricOfAMonthWithoutDataOrInTheFutureIsNullWithItsState(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, GoalMetric::NET_SAVINGS_TRANSFERS);
        $inputs = new GoalStatusInputs([], null);

        foreach ([MonthState::NO_DATA, MonthState::FUTURE] as $state) {
            $metric = $this->reader([])->metric($goal, new AnnualMonth(new CalendarMonth(2026, 1), $state, false, false, null), [], $inputs);

            self::assertNull($metric['value']);
            self::assertSame(MonthState::FUTURE === $state ? GoalReason::FUTURE_MONTH : GoalReason::NO_DATA, $metric['reason']);
        }
    }

    public function testMetricReadsTheCellOfTheGoalMetricExactly(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, GoalMetric::NET_SAVINGS_TRANSFERS);
        $month = self::month(2026, 1, MonthState::COMPLETE, 'netSavingsTransfers', '180.10');

        $metric = $this->reader([])->metric($goal, $month, [], new GoalStatusInputs([], null));

        self::assertSame('180.10', $metric['value']?->toString());
        self::assertNull($metric['reason']);
    }

    public function testMetricIsNullWhenTheCellIsMissingOrHasNoValue(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, GoalMetric::NET_SAVINGS_TRANSFERS);
        $reader = $this->reader([]);
        $inputs = new GoalStatusInputs([], null);

        $missing = $reader->metric($goal, self::month(2026, 1, MonthState::COMPLETE, 'cashIncome', '1.00'), [], $inputs);
        $empty = $reader->metric($goal, self::month(2026, 1, MonthState::COMPLETE, 'netSavingsTransfers', null), [], $inputs);

        self::assertNull($missing['value']);
        self::assertSame(GoalReason::MISSING_MONTH_VALUE, $missing['reason']);
        self::assertNull($empty['value']);
        self::assertSame(GoalReason::NO_DATA, $empty['reason']);
    }

    public function testMetricInAnotherCurrencyIsMixedAssets(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, GoalMetric::NET_SAVINGS_TRANSFERS);
        $month = new AnnualMonth(new CalendarMonth(2026, 1), MonthState::COMPLETE, false, false, new MonthFigures('2026-01', 1, 'CURRENT', 0, '2026-01-31', [
            'netSavingsTransfers' => new MonthlyMetricView('5.00', 'USD', null),
        ], [], [], []));

        $metric = $this->reader([])->metric($goal, $month, [], new GoalStatusInputs([], null));

        self::assertNull($metric['value']);
        self::assertSame(GoalReason::MIXED_ASSETS, $metric['reason']);
    }

    public function testTotalIncomeWithoutTransactionsIsNoData(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, GoalMetric::TOTAL_INCOME);
        $month = new AnnualMonth(new CalendarMonth(2026, 1), MonthState::COMPLETE, false, false, new MonthFigures('2026-01', 1, 'CURRENT', 0, '2026-01-31', [], [], [], null, false));

        $metric = $this->reader([])->metric($goal, $month, [], new GoalStatusInputs([], null));

        self::assertNull($metric['value']);
        self::assertSame(GoalReason::NO_DATA, $metric['reason']);
    }

    public function testCategorySpendingSumsTheBranchOwnFiguresAndSkipsMovementlessCategories(): void
    {
        $goal = self::goal(GoalPeriodicity::MONTHLY, GoalMetric::CATEGORY_SPENDING, GoalScopeType::CATEGORY, 'c1');
        $month = new AnnualMonth(new CalendarMonth(2026, 1), MonthState::COMPLETE, false, false, new MonthFigures('2026-01', 1, 'CURRENT', 0, '2026-01-31', [
            'budgetExpenses' => new MonthlyMetricView('30.00', 'EUR', null),
            MonthFigures::OWN_CATEGORY_PREFIX.'c1' => new MonthlyMetricView('10.50', 'EUR', null),
            MonthFigures::OWN_CATEGORY_PREFIX.'c2' => new MonthlyMetricView('0.25', 'EUR', null),
            MonthFigures::OWN_CATEGORY_PREFIX.'c3' => new MonthlyMetricView(null, null, 'NO_MOVEMENTS'),
        ], ['c1', 'c2', 'c3'], [], []));

        $metric = $this->reader([])->metric($goal, $month, ['c1', 'c2', 'c3'], new GoalStatusInputs([], null));

        self::assertSame('10.75', $metric['value']?->toString());
        self::assertNull($metric['reason']);
    }

    public function testCategoryBranchWithoutFactsFallsBackToTheScopeReference(): void
    {
        $reader = $this->reader([]);
        $inputs = new GoalStatusInputs([], null);

        self::assertSame(['c1'], $reader->categoryBranch(self::goal(GoalPeriodicity::MONTHLY, GoalMetric::CATEGORY_SPENDING, GoalScopeType::CATEGORY, 'c1'), $inputs));
        self::assertSame([], $reader->categoryBranch(self::goal(GoalPeriodicity::MONTHLY, GoalMetric::TOTAL_SPENDING), $inputs));
    }

    public function testCategoryBranchCollectsDescendantsSortedAndExcludesSiblings(): void
    {
        $fact = static fn (string $id, ?string $parent): BudgetCategoryFact => new BudgetCategoryFact($id, $parent, true, 'EXPENSE', $id, null, null, null);
        $inputs = new GoalStatusInputs([], [$fact('c3', 'c1'), $fact('c1', null), $fact('c2', 'c1'), $fact('c4', null)]);

        $ids = $this->reader([])->categoryBranch(self::goal(GoalPeriodicity::MONTHLY, GoalMetric::CATEGORY_SPENDING, GoalScopeType::CATEGORY, 'c1'), $inputs);

        self::assertSame(['c1', 'c2', 'c3'], $ids);
    }

    public function testPolicyFromVersionsDeduplicatesAndSorts(): void
    {
        $none = GoalMetricReader::policyFromVersions([]);
        $single = GoalMetricReader::policyFromVersions([2, 2]);
        $mixed = GoalMetricReader::policyFromVersions([3, 1, 3]);

        self::assertSame([GoalPolicyState::NONE, null, []], [$none->state, $none->version, $none->versions]);
        self::assertSame([GoalPolicyState::SINGLE, 2, [2]], [$single->state, $single->version, $single->versions]);
        self::assertSame([GoalPolicyState::MIXED, null, [1, 3]], [$mixed->state, $mixed->version, $mixed->versions]);
    }

    /** @param list<AnnualMonth> $months */
    private function reader(array $months): GoalMetricReader
    {
        $source = new class($months) implements GoalMonthSource {
            /** @param list<AnnualMonth> $months */
            public function __construct(private array $months)
            {
            }

            public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end, bool $includeUpcoming = false): array
            {
                return array_values(array_filter($this->months, static fn (AnnualMonth $month): bool => $month->month->key() >= $start->key() && $month->month->key() <= $end->key()));
            }
        };
        $pairs = new class implements MonthlyTransferPairReader {
            public function read(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
            {
                return [];
            }
        };

        return new GoalMetricReader($source, new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($pairs)));
    }

    private static function goal(GoalPeriodicity $periodicity, GoalMetric $metric, GoalScopeType $scope = GoalScopeType::WORKSPACE, ?string $scopeRef = null): Goal
    {
        $start = GoalPeriodicity::YEARLY === $periodicity ? '2026' : '2026-01';

        return new Goal(self::GOAL_ID, WorkspaceFixture::own(), 'Goal', $periodicity, $metric, $scope, $scopeRef, AssetCode::fromString('EUR'), $start, null, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
    }

    private static function month(int $year, int $index, MonthState $state, string $cell, ?string $value): AnnualMonth
    {
        $month = new CalendarMonth($year, $index);

        return new AnnualMonth($month, $state, false, false, new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), [
            $cell => new MonthlyMetricView($value, null === $value ? null : 'EUR', null === $value ? 'NO_ACCOUNT' : null),
        ], [], [], []));
    }
}
