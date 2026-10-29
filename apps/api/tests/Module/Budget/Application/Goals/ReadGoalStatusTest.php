<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Application\ReadAccountReference;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Budget\Application\Goals\ReadGoalStatus;
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
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Categories\Application\Double\InMemoryCategoryRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReadGoalStatusTest extends TestCase
{
    private const string GOAL_ID = '00000000-0000-7000-8000-000000000101';
    private const string VERSION_ID = '00000000-0000-7000-8000-000000000102';
    private const string CATEGORY_ID = '00000000-0000-7000-8000-000000000201';
    private const string ACCOUNT_ID = '00000000-0000-7000-8000-000000000301';

    public function testAnnualMinimumUsesExactCumulativeValuesAndSchedule(): void
    {
        $months = [];
        for ($index = 1; $index <= 9; ++$index) {
            $months[] = self::month(2026, $index, 9 === $index ? MonthState::PROVISIONAL : MonthState::COMPLETE, 'netSavingsTransfers', '180.00');
        }
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);
        $version = self::annualVersion(1, '2026-01', '2000.00');

        $status = $this->reader($goal, [$version], $months)(self::GOAL_ID, '2026-09');

        self::assertSame('1620.00', $status->achieved);
        self::assertSame('1500.03', $status->expected);
        self::assertSame('119.97', $status->gap);
        self::assertSame('380.00', $status->remaining);
        self::assertSame('0', $status->excess);
        self::assertSame('AHEAD', $status->status);
        self::assertSame('0.810000000000000000000000', $status->progressRatio);
        self::assertSame('PROVISIONAL', $status->quality);
        self::assertTrue($status->provisional);
        self::assertCount(9, $status->months);
    }

    public function testAnnualCeilingDistinguishesOverPaceFromExceeded(): void
    {
        $months = [];
        for ($index = 1; $index <= 5; ++$index) {
            $months[] = self::month(2026, $index, MonthState::COMPLETE, 'axis:DISCRETIONARY', '150.00');
        }
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::AXIS_SPENDING, GoalScopeType::AXIS, 'DISCRETIONARY');

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months)(self::GOAL_ID, '2026-05');

        self::assertSame('750.00', $status->achieved);
        self::assertSame('500.00', $status->expected);
        self::assertSame('OVER_PACE', $status->status);
    }

    public function testAnnualMinimumSeparatesRemainingAndExcessAfterReachingTheTarget(): void
    {
        $months = [];
        for ($index = 1; $index <= 8; ++$index) {
            $months[] = self::month(2026, $index, MonthState::COMPLETE, 'netSavingsTransfers', '260.00');
        }
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '2000.00')], $months)(self::GOAL_ID, '2026-08');

        self::assertSame('2080.00', $status->achieved);
        self::assertSame('REACHED', $status->status);
        self::assertSame('0', $status->remaining);
        self::assertSame('80.00', $status->excess);
        self::assertSame('1', $status->progressRatioDisplay);
    }

    public function testAnnualMinimumIsReachedAtExactTargetEquality(): void
    {
        $months = [];
        for ($index = 1; $index <= 8; ++$index) {
            $months[] = self::month(2026, $index, MonthState::COMPLETE, 'netSavingsTransfers', '250.00');
        }
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '2000.00')], $months)(self::GOAL_ID, '2026-08');

        self::assertSame('2000.00', $status->achieved);
        self::assertSame('REACHED', $status->status);
        self::assertSame('0', $status->remaining);
        self::assertSame('0', $status->excess);
    }

    public function testAnnualResponseArithmeticMayExceedTheStorageIntegerWidth(): void
    {
        $maximum = '99999999999999999999999999';
        $target = DecimalValue::fromString($maximum);
        $schedule = GoalSchedule::custom($target, [$target, ...array_fill(0, 11, DecimalValue::zero())], 0);
        $version = GoalVersion::annual(self::VERSION_ID, WorkspaceFixture::own(), self::GOAL_ID, 1, '2026-01', 2026, $target, $schedule, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);
        $months = [self::month(2026, 1, MonthState::COMPLETE, 'netSavingsTransfers', '-'.$maximum)];

        $status = $this->reader($goal, [$version], $months)(self::GOAL_ID, '2026-09');

        self::assertSame('-199999999999999999999999998', $status->gap);
        self::assertSame('199999999999999999999999998', $status->remaining);
        self::assertSame('0', $status->excess);
        self::assertSame('-1.000000000000000000000000', $status->progressRatio);
        self::assertSame('0', $status->progressRatioDisplay);
    }

    public function testAnnualCumulativeAndAggregateMayExceedTheStorageIntegerWidth(): void
    {
        $maximum = '99999999999999999999999999';
        $target = DecimalValue::fromString($maximum);
        $schedule = GoalSchedule::custom($target, [$target, ...array_fill(0, 11, DecimalValue::zero())], 0);
        $version = GoalVersion::annual(self::VERSION_ID, WorkspaceFixture::own(), self::GOAL_ID, 1, '2026-01', 2026, $target, $schedule, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);
        $months = [
            self::month(2026, 1, MonthState::COMPLETE, 'netSavingsTransfers', $maximum),
            self::month(2026, 2, MonthState::COMPLETE, 'netSavingsTransfers', $maximum),
        ];

        $status = $this->reader($goal, [$version], $months)(self::GOAL_ID, '2026-02');

        self::assertSame('199999999999999999999999998', $status->achieved);
        self::assertSame('199999999999999999999999998', $status->months[1]->cumulativeValue);
        self::assertSame('REACHED', $status->status);
        self::assertSame($maximum, $status->excess);
        self::assertSame('2.000000000000000000000000', $status->progressRatio);
        self::assertSame('1', $status->progressRatioDisplay);
    }

    public function testAnnualCeilingIsExceededAtOneThousandTwoHundredFiftyByNovember(): void
    {
        $months = [];
        for ($index = 1; $index <= 11; ++$index) {
            $months[] = self::month(2026, $index, MonthState::COMPLETE, 'axis:DISCRETIONARY', 11 === $index ? '250.00' : '100.00');
        }
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::AXIS_SPENDING, GoalScopeType::AXIS, 'DISCRETIONARY');

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months)(self::GOAL_ID, '2026-11');

        self::assertSame('1250.00', $status->achieved);
        self::assertSame('EXCEEDED', $status->status);
        self::assertSame('50.00', $status->excess);
        self::assertSame('0', $status->remaining);
    }

    public function testAnnualStatusUsesEachMonthsEffectiveVersionAndReportsPartialData(): void
    {
        $months = [];
        for ($index = 1; $index <= 9; ++$index) {
            $state = $index < 4 ? MonthState::NO_DATA : MonthState::COMPLETE;
            $months[] = self::month(2026, $index, $state, 'netSavingsTransfers', MonthState::NO_DATA === $state ? null : '150.00');
        }
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);
        $version1 = self::annualVersion(1, '2026-01', '1200.00');
        $version2 = self::annualVersion(2, '2026-07', '2400.00');

        $status = $this->reader($goal, [$version2, $version1], $months)(self::GOAL_ID, '2026-09');

        self::assertSame('900.00', $status->achieved);
        self::assertSame('1200.00', $status->expected);
        self::assertSame('PARTIAL', $status->quality);
        self::assertSame(1, $status->months[5]->goalVersion);
        self::assertSame(2, $status->months[6]->goalVersion);
        self::assertSame(2, $status->goalVersion);
        self::assertSame('2400.00', $status->target);
    }

    public function testMixedMetricPoliciesMakeTheAnnualTotalExplicitlyNonCalculable(): void
    {
        $months = [
            self::month(2026, 1, MonthState::COMPLETE, 'netSavingsTransfers', '100.00', 1),
            self::month(2026, 2, MonthState::COMPLETE, 'netSavingsTransfers', '100.00', 2),
        ];
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months)(self::GOAL_ID, '2026-02');

        self::assertNull($status->achieved);
        self::assertSame('NOT_CALCULABLE', $status->status);
        self::assertSame('MIXED_METRIC_POLICIES', $status->reason);
        self::assertSame('MIXED', $status->metricPolicy->state);
        self::assertSame([1, 2], $status->metricPolicy->versions);
    }

    public function testRollingCeilingUsesExactReferenceIncomeTarget(): void
    {
        $months = [];
        for ($ordinal = 0; $ordinal < 12; ++$ordinal) {
            $date = (new \DateTimeImmutable('2025-09-01'))->modify(sprintf('+%d months', $ordinal));
            $state = $ordinal < 2 ? MonthState::NO_DATA : MonthState::COMPLETE;
            $months[] = self::month((int) $date->format('Y'), (int) $date->format('n'), $state, 'cashIncome', MonthState::NO_DATA === $state ? null : '2500.00');
        }
        $months[] = self::month(2026, 9, MonthState::PROVISIONAL, 'axis:DISCRETIONARY', '800.00');
        $goal = self::goal(GoalKind::ROLLING_RATIO, GoalMetric::AXIS_SPENDING, GoalScopeType::AXIS, 'DISCRETIONARY');
        $version = GoalVersion::rolling(self::VERSION_ID, WorkspaceFixture::own(), self::GOAL_ID, 1, '2026-09', DecimalValue::fromString('0.30'), new \DateTimeImmutable('2026-09-01'), WorkspaceFixture::OWNER_ID);

        $status = $this->reader($goal, [$version], $months)(self::GOAL_ID, '2026-09');

        self::assertSame('2500.000000000000000000000000', $status->referenceIncome?->value?->toString());
        self::assertSame('750.000000000000000000000000', $status->target);
        self::assertSame('800.00', $status->actual);
        self::assertSame('50.000000000000000000000000', $status->gap);
        self::assertSame('OVER', $status->status);
    }

    public function testNegativeRollingSavingsRemainNegativeAndMissTheMinimum(): void
    {
        $months = [];
        for ($ordinal = 0; $ordinal < 12; ++$ordinal) {
            $date = (new \DateTimeImmutable('2025-09-01'))->modify(sprintf('+%d months', $ordinal));
            $months[] = self::month((int) $date->format('Y'), (int) $date->format('n'), MonthState::COMPLETE, 'cashIncome', '2500.00');
        }
        $months[] = self::month(2026, 9, MonthState::PROVISIONAL, 'netSavingsTransfers', '-120.00');
        $goal = self::goal(GoalKind::ROLLING_RATIO, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);
        $version = GoalVersion::rolling(self::VERSION_ID, WorkspaceFixture::own(), self::GOAL_ID, 1, '2026-09', DecimalValue::fromString('0.20'), new \DateTimeImmutable('2026-09-01'), WorkspaceFixture::OWNER_ID);

        $status = $this->reader($goal, [$version], $months)(self::GOAL_ID, '2026-09');

        self::assertSame('-120.00', $status->actual);
        self::assertSame('-620.000000000000000000000000', $status->gap);
        self::assertSame('MISSED', $status->status);
    }

    public function testRollingStatusBlocksWhenReferenceAndActualUseDifferentMetricPolicies(): void
    {
        $months = [];
        for ($ordinal = 0; $ordinal < 12; ++$ordinal) {
            $date = (new \DateTimeImmutable('2025-09-01'))->modify(sprintf('+%d months', $ordinal));
            $months[] = self::month((int) $date->format('Y'), (int) $date->format('n'), MonthState::COMPLETE, 'cashIncome', '2500.00', 1);
        }
        $months[] = self::month(2026, 9, MonthState::PROVISIONAL, 'netSavingsTransfers', '600.00', 2);
        $goal = self::goal(GoalKind::ROLLING_RATIO, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);
        $version = GoalVersion::rolling(self::VERSION_ID, WorkspaceFixture::own(), self::GOAL_ID, 1, '2026-09', DecimalValue::fromString('0.20'), new \DateTimeImmutable('2026-09-01'), WorkspaceFixture::OWNER_ID);

        $status = $this->reader($goal, [$version], $months)(self::GOAL_ID, '2026-09');

        self::assertSame('NOT_CALCULABLE', $status->status);
        self::assertSame('MIXED_METRIC_POLICIES', $status->reason);
        self::assertSame('MIXED', $status->metricPolicy->state);
        self::assertSame([1, 2], $status->metricPolicy->versions);
        // Target 2500 x 0.20 = 500 and actual 600 both exist, yet mixed policies make 600 - 500 meaningless.
        self::assertNull($status->gap);
    }

    public function testRollingGoalWithoutReferenceIncomeKeepsItsActualButNoTargetOrGap(): void
    {
        $months = [];
        for ($ordinal = 0; $ordinal < 12; ++$ordinal) {
            $date = (new \DateTimeImmutable('2025-09-01'))->modify(sprintf('+%d months', $ordinal));
            $months[] = self::month((int) $date->format('Y'), (int) $date->format('n'), MonthState::NO_DATA, 'cashIncome', null);
        }
        $months[] = self::month(2026, 9, MonthState::COMPLETE, 'netSavingsTransfers', '300.00');
        $goal = self::goal(GoalKind::ROLLING_RATIO, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);

        $status = $this->reader($goal, [self::rollingVersion('2026-01', '0.20')], $months)(self::GOAL_ID, '2026-09');

        self::assertNull($status->referenceIncome?->value);
        self::assertSame(0, $status->referenceIncome?->usableMonths);
        self::assertSame('300.00', $status->actual);
        self::assertNull($status->target);
        self::assertNull($status->gap);
        self::assertSame('NOT_CALCULABLE', $status->status);
        self::assertSame('NO_REFERENCE_INCOME', $status->reason);
    }

    public function testAnnualGoalBeforeItsYearIsNotStarted(): void
    {
        $months = [self::month(2025, 12, MonthState::COMPLETE, 'netSavingsTransfers', '0')];
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months)(self::GOAL_ID, '2025-12');

        self::assertNull($status->goalVersion);
        self::assertNull($status->target);
        self::assertNull($status->expected);
        self::assertNull($status->gap);
        self::assertNull($status->progressRatio);
        self::assertSame('NOT_CALCULABLE', $status->status);
        self::assertSame('GOAL_NOT_STARTED', $status->reason);
    }

    public function testRollingGoalBeforeItsFirstEffectiveMonthIsNotStarted(): void
    {
        $months = self::referenceIncomes('2500.00');
        $months[] = self::month(2026, 8, MonthState::COMPLETE, 'netSavingsTransfers', '0');
        $goal = self::goal(GoalKind::ROLLING_RATIO, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);

        $status = $this->reader($goal, [self::rollingVersion('2026-09', '0.20')], $months)(self::GOAL_ID, '2026-08');

        self::assertNull($status->goalVersion);
        self::assertNull($status->ratio);
        self::assertNull($status->target);
        self::assertSame('0', $status->actual);
        self::assertNull($status->gap);
        self::assertSame('NOT_CALCULABLE', $status->status);
        self::assertSame('GOAL_NOT_STARTED', $status->reason);
    }

    public function testRollingMonthWithoutDataIsPartialAndNeverMetOrMissed(): void
    {
        $months = self::referenceIncomes('2500.00');
        $goal = self::goal(GoalKind::ROLLING_RATIO, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);

        $status = $this->reader($goal, [self::rollingVersion('2026-01', '0.20')], $months)(self::GOAL_ID, '2026-09');

        self::assertSame('500.000000000000000000000000', $status->target);
        self::assertNull($status->actual);
        self::assertNull($status->gap);
        self::assertSame('NOT_CALCULABLE', $status->status);
        self::assertSame('MISSING_MONTH_VALUE', $status->reason);
        self::assertSame('PARTIAL', $status->quality);
    }

    public function testCompleteMonthWithoutValueInMidYearMakesAchievedNonCalculable(): void
    {
        // Jan-Mar 100.00 each, April COMPLETE but null, May 100.00: 400.00 would silently read April as zero.
        $months = [];
        for ($index = 1; $index <= 5; ++$index) {
            $months[] = self::month(2026, $index, MonthState::COMPLETE, 'netSavingsTransfers', 4 === $index ? null : '100.00');
        }
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months)(self::GOAL_ID, '2026-05');

        self::assertNull($status->achieved);
        self::assertSame('500.00', $status->expected);
        self::assertNull($status->gap);
        self::assertNull($status->remaining);
        self::assertNull($status->progressRatio);
        self::assertSame('NOT_CALCULABLE', $status->status);
        self::assertSame('MISSING_MONTH_VALUE', $status->reason);
        self::assertSame('300.00', $status->months[2]->cumulativeValue);
        self::assertNull($status->months[3]->value);
        self::assertNull($status->months[3]->cumulativeValue);
        self::assertNull($status->months[4]->cumulativeValue);
        self::assertSame('500.00', $status->months[4]->cumulativeExpected);
    }

    public function testArchivedCategoryKeepsTheRollingStatusCalculable(): void
    {
        // Reference 2500 x 0.10 = 250 target; 300.00 spent: gap 50, over the ceiling, the archive only warns.
        $months = self::referenceIncomes('2500.00');
        $months[] = self::monthWith(2026, 9, MonthState::PROVISIONAL, ['budgetExpenses' => '300.00', 'ownCategory:'.self::CATEGORY_ID => '300.00'], [self::CATEGORY_ID]);
        $categories = new InMemoryCategoryRepository();
        $categories->add(self::category(self::CATEGORY_ID, null, WorkspaceFixture::own(), new \DateTimeImmutable('2026-06-01')));
        $goal = self::goal(GoalKind::ROLLING_RATIO, GoalMetric::CATEGORY_SPENDING, GoalScopeType::CATEGORY, self::CATEGORY_ID);

        $status = $this->reader($goal, [self::rollingVersion('2026-01', '0.10')], $months, $categories)(self::GOAL_ID, '2026-09');

        self::assertSame('250.000000000000000000000000', $status->target);
        self::assertSame('300.00', $status->actual);
        self::assertSame('50.000000000000000000000000', $status->gap);
        self::assertSame('OVER', $status->status);
        self::assertSame('REFERENCE_ARCHIVED', $status->reason);
    }

    public function testCategorySpendingSumsTheOwnBranchExactly(): void
    {
        // January: parent 40.10 + child 9.905000000000000000000001 = 50.005000000000000000000001 (sibling 999.00 excluded).
        // February: parent 10.00, child without movement: cumulative 60.005000000000000000000001 against 200.00 expected.
        $child = '00000000-0000-7000-8000-000000000202';
        $sibling = '00000000-0000-7000-8000-000000000203';
        $foreignChild = '00000000-0000-7000-8000-000000000204';
        $categories = new InMemoryCategoryRepository();
        $categories->add(self::category(self::CATEGORY_ID, null, WorkspaceFixture::own()));
        $categories->add(self::category($child, self::CATEGORY_ID, WorkspaceFixture::own()));
        $categories->add(self::category($sibling, null, WorkspaceFixture::own()));
        $categories->add(self::category($foreignChild, self::CATEGORY_ID, WorkspaceFixture::other()));
        $ids = [self::CATEGORY_ID, $child, $sibling, $foreignChild];
        $months = [
            self::monthWith(2026, 1, MonthState::COMPLETE, ['budgetExpenses' => '1500.00', 'ownCategory:'.self::CATEGORY_ID => '40.10', 'ownCategory:'.$child => '9.905000000000000000000001', 'ownCategory:'.$sibling => '999.00', 'ownCategory:'.$foreignChild => '450.00'], $ids),
            self::monthWith(2026, 2, MonthState::COMPLETE, ['budgetExpenses' => '10.00', 'ownCategory:'.self::CATEGORY_ID => '10.00', 'ownCategory:'.$child => null], $ids),
        ];
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::CATEGORY_SPENDING, GoalScopeType::CATEGORY, self::CATEGORY_ID);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months, $categories)(self::GOAL_ID, '2026-02');

        self::assertSame('50.005000000000000000000001', $status->months[0]->value);
        self::assertSame('10.00', $status->months[1]->value);
        self::assertSame('60.005000000000000000000001', $status->achieved);
        self::assertSame('200.00', $status->expected);
        self::assertSame('ON_TRACK', $status->status);
        self::assertNull($status->reason);
    }

    public function testAccountContributionsAddTheAccountsBookedTransferNetsExactly(): void
    {
        // January: +250.125 received, a voided 1000.00 ignored. February: -50.000000000000000000000001 sent.
        // Cumulative 200.124999999999999999999999 against 200.00 expected by February: ahead of a minimum.
        $other = '00000000-0000-7000-8000-000000000302';
        $pairs = [
            '2026-01' => [
                self::pair('00000000-0000-7000-8000-000000000401', $other, self::ACCOUNT_ID, '250.125', '2026-01-15'),
                self::pair('00000000-0000-7000-8000-000000000402', self::ACCOUNT_ID, $other, '1000.00', '2026-01-20', true),
            ],
            '2026-02' => [self::pair('00000000-0000-7000-8000-000000000403', self::ACCOUNT_ID, $other, '50.000000000000000000000001', '2026-02-03')],
        ];
        $months = [
            self::month(2026, 1, MonthState::COMPLETE, 'netSavingsTransfers', '0'),
            self::month(2026, 2, MonthState::COMPLETE, 'netSavingsTransfers', '0'),
        ];
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalScopeType::ACCOUNT, self::ACCOUNT_ID);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months, transfers: new FixedTransferPairReader($pairs))(self::GOAL_ID, '2026-02');

        self::assertSame('250.125', $status->months[0]->value);
        self::assertSame('-50.000000000000000000000001', $status->months[1]->value);
        self::assertSame('200.124999999999999999999999', $status->achieved);
        self::assertSame('0.124999999999999999999999', $status->gap);
        self::assertSame('AHEAD', $status->status);
    }

    public function testAFigureInAnotherCurrencyThanTheGoalIsNotCalculable(): void
    {
        $month = new CalendarMonth(2026, 1);
        $figures = new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), [
            'netSavingsTransfers' => new MonthlyMetricView('100.00', 'USD', null),
        ], [], [], []);
        $months = [new AnnualMonth($month, MonthState::COMPLETE, false, false, $figures)];
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months)(self::GOAL_ID, '2026-01');

        self::assertNull($status->months[0]->value);
        self::assertNull($status->achieved);
        self::assertSame('NOT_CALCULABLE', $status->status);
    }

    public function testAnAccountTransferNetInAnotherCurrencyThanTheGoalIsNotCalculable(): void
    {
        $other = '00000000-0000-7000-8000-000000000302';
        $pairs = ['2026-01' => [self::pair('00000000-0000-7000-8000-000000000401', $other, self::ACCOUNT_ID, '250.00', '2026-01-15', assetCode: 'USD')]];
        $months = [self::month(2026, 1, MonthState::COMPLETE, 'netSavingsTransfers', '0')];
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalScopeType::ACCOUNT, self::ACCOUNT_ID);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months, transfers: new FixedTransferPairReader($pairs))(self::GOAL_ID, '2026-01');

        self::assertNull($status->months[0]->value);
        self::assertSame('NOT_CALCULABLE', $status->status);
    }

    public function testAccountContributionsOfAnotherWorkspaceAreNeverRead(): void
    {
        $months = [self::month(2026, 1, MonthState::COMPLETE, 'netSavingsTransfers', '0')];
        $pairs = ['2026-01' => [self::pair('00000000-0000-7000-8000-000000000401', '00000000-0000-7000-8000-000000000302', self::ACCOUNT_ID, '250.125', '2026-01-15')]];
        $goal = self::goal(GoalKind::ANNUAL, GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalScopeType::ACCOUNT, self::ACCOUNT_ID);

        $status = $this->reader($goal, [self::annualVersion(1, '2026-01', '1200.00')], $months, transfers: new FixedTransferPairReader($pairs, WorkspaceFixture::other()))(self::GOAL_ID, '2026-01');

        self::assertSame('0', $status->achieved);
    }

    /**
     * @param list<GoalVersion> $versions
     * @param list<AnnualMonth> $months
     */
    private function reader(Goal $goal, array $versions, array $months, ?InMemoryCategoryRepository $categories = null, ?MonthlyTransferPairReader $transfers = null): ReadGoalStatus
    {
        $caller = $this->createStub(CallerWorkspace::class);
        $caller->method('resolve')->willReturn(WorkspaceFixture::own());
        $goals = $this->createStub(GoalRepository::class);
        $goals->method('find')->willReturn($goal);
        $repository = $this->createStub(GoalVersionRepository::class);
        $repository->method('listByGoal')->willReturn($versions);
        $source = new GoalStatusMonthSource($months);

        return new ReadGoalStatus(
            $caller,
            $goals,
            $repository,
            $source,
            new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($transfers ?? new EmptyTransferPairReader())),
            self::calendar(),
            null === $categories ? null : new ResolveGoalScope(new ReadCategoryReference($categories), new ReadAccountReference(new InMemoryAccountRepository())),
            null === $categories ? null : new ReadBudgetCategoryFacts($categories),
        );
    }

    private static function goal(GoalKind $kind, GoalMetric $metric, GoalScopeType $scope, ?string $scopeRef = null): Goal
    {
        return new Goal(self::GOAL_ID, WorkspaceFixture::own(), 'Goal', $kind, $metric, $scope, $scopeRef, AssetCode::fromString('EUR'), GoalState::ACTIVE, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
    }

    private static function annualVersion(int $version, string $effectiveFrom, string $target): GoalVersion
    {
        $amount = DecimalValue::fromString($target);

        return GoalVersion::annual(self::VERSION_ID, WorkspaceFixture::own(), self::GOAL_ID, $version, $effectiveFrom, 2026, $amount, GoalSchedule::linear($amount, 2), new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
    }

    private static function rollingVersion(string $effectiveFrom, string $ratio): GoalVersion
    {
        return GoalVersion::rolling(self::VERSION_ID, WorkspaceFixture::own(), self::GOAL_ID, 1, $effectiveFrom, DecimalValue::fromString($ratio), new \DateTimeImmutable($effectiveFrom.'-01'), WorkspaceFixture::OWNER_ID);
    }

    /** @return list<AnnualMonth> the twelve complete months before 2026-09, each with the same cash income */
    private static function referenceIncomes(string $income): array
    {
        $months = [];
        for ($ordinal = 0; $ordinal < 12; ++$ordinal) {
            $date = (new \DateTimeImmutable('2025-09-01'))->modify(sprintf('+%d months', $ordinal));
            $months[] = self::month((int) $date->format('Y'), (int) $date->format('n'), MonthState::COMPLETE, 'cashIncome', $income);
        }

        return $months;
    }

    /**
     * @param array<string, ?string> $cells
     * @param list<string>           $budgetCategoryIds
     */
    private static function monthWith(int $year, int $index, MonthState $state, array $cells, array $budgetCategoryIds): AnnualMonth
    {
        $month = new CalendarMonth($year, $index);
        $views = array_map(static fn (?string $value): MonthlyMetricView => new MonthlyMetricView($value, null === $value ? null : 'EUR', null === $value ? 'NO_MOVEMENTS' : null), $cells);

        return new AnnualMonth($month, $state, false, false, new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), $views, $budgetCategoryIds, [], []));
    }

    private static function category(string $id, ?string $parentId, WorkspaceScope $workspace, ?\DateTimeImmutable $archivedAt = null): Category
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Category($id, $workspace, CategoryType::EXPENSE, 'Category', $parentId, null, null, [], true, 0, null === $parentId ? 1 : 2, 1, $now, $now, archivedAt: $archivedAt);
    }

    private static function pair(string $transferId, string $source, string $target, string $amount, string $bookedOn, bool $voided = false, string $assetCode = 'EUR'): MonthlyTransferPairFact
    {
        $asset = AssetCode::fromString($assetCode);

        return new MonthlyTransferPairFact($transferId, null, null, $source, $target, DecimalValue::fromString('-'.$amount), DecimalValue::fromString($amount), $asset, $asset, 'BOOKED', 'BOOKED', $bookedOn, $bookedOn, $voided);
    }

    private static function month(int $year, int $index, MonthState $state, string $cell, ?string $value, int $policy = 1): AnnualMonth
    {
        $month = new CalendarMonth($year, $index);
        $figures = MonthState::NO_DATA === $state ? null : new MonthFigures($month->key(), $policy, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), [
            $cell => new MonthlyMetricView($value, null === $value ? null : 'EUR', null === $value ? 'NO_ACCOUNT' : null),
        ], [], [], []);

        return new AnnualMonth($month, $state, false, false, $figures);
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

        return new WorkspaceCalendar(new MockClock('2026-12-24T12:00:00+01:00'), $context, $timezones);
    }
}

final readonly class GoalStatusMonthSource implements GoalMonthSource
{
    /** @param list<AnnualMonth> $months */
    public function __construct(private array $months)
    {
    }

    public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end): array
    {
        return array_values(array_filter($this->months, static fn (AnnualMonth $month): bool => $month->month->key() >= $start->key() && $month->month->key() <= $end->key()));
    }
}

final readonly class FixedTransferPairReader implements MonthlyTransferPairReader
{
    /** @param array<string, list<MonthlyTransferPairFact>> $pairsByMonth */
    public function __construct(private array $pairsByMonth, private ?WorkspaceScope $owner = null)
    {
    }

    public function read(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        if (!$workspace->equals($this->owner ?? WorkspaceFixture::own())) {
            return [];
        }

        return $this->pairsByMonth[$from->format('Y-m')] ?? [];
    }
}

final readonly class EmptyTransferPairReader implements MonthlyTransferPairReader
{
    public function read(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        return [];
    }
}
