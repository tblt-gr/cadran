<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\InvalidGoal;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoalTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string ACTOR = '00000000-0000-7000-8000-000000000003';

    public function testAYearlyAmountGoalDerivesItsDirectionAndKeepsDefinitionsInVersions(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, GoalMetric::CATEGORY_SPENDING, GoalScopeType::CATEGORY, '00000000-0000-7000-8000-000000000002', '2026', '2026');
        $definition = GoalVersion::amount(
            '00000000-0000-7000-8000-000000000004', $goal->workspace, $goal->id, 1, '2026-01',
            DecimalValue::fromString('1200.00'), GoalSchedule::linear(DecimalValue::fromString('1200.00'), 2),
            new \DateTimeImmutable('2026-09-01'), self::ACTOR,
        );

        self::assertSame('CEILING', $goal->direction()->value);
        self::assertSame(GoalValueType::AMOUNT, $definition->valueType);
        self::assertSame('LINEAR', $definition->scheduleMode());
        self::assertSame('1200.00', $definition->targetAmount?->toString());
        self::assertNull($definition->ratio);
    }

    /** @return iterable<string, array{GoalMetric, GoalPeriodicity, GoalValueType, GoalScopeType, bool}> */
    public static function matrix(): iterable
    {
        foreach ([GoalMetric::TOTAL_INCOME, GoalMetric::TOTAL_SPENDING] as $metric) {
            foreach (GoalPeriodicity::cases() as $periodicity) {
                foreach (GoalValueType::cases() as $type) {
                    foreach (GoalScopeType::cases() as $scope) {
                        yield $metric->value.' '.$periodicity->value.' '.$type->value.' '.$scope->value => [$metric, $periodicity, $type, $scope, GoalValueType::AMOUNT === $type && GoalScopeType::WORKSPACE === $scope];
                    }
                }
            }
        }
        yield 'net worth amount' => [GoalMetric::NET_WORTH, GoalPeriodicity::YEARLY, GoalValueType::AMOUNT, GoalScopeType::WORKSPACE, true];
        yield 'balance account' => [GoalMetric::ACCOUNT_BALANCE, GoalPeriodicity::MONTHLY, GoalValueType::AMOUNT, GoalScopeType::ACCOUNT, true];
        yield 'stock refuses share' => [GoalMetric::NET_WORTH, GoalPeriodicity::YEARLY, GoalValueType::INCOME_SHARE, GoalScopeType::WORKSPACE, false];
        yield 'savings rate takes a rate monthly' => [GoalMetric::SAVINGS_RATE, GoalPeriodicity::MONTHLY, GoalValueType::RATE, GoalScopeType::WORKSPACE, true];
        yield 'savings rate takes a rate yearly' => [GoalMetric::SAVINGS_RATE, GoalPeriodicity::YEARLY, GoalValueType::RATE, GoalScopeType::WORKSPACE, true];
        yield 'net savings rate takes a rate' => [GoalMetric::NET_SAVINGS_RATE, GoalPeriodicity::MONTHLY, GoalValueType::RATE, GoalScopeType::WORKSPACE, true];
        yield 'savings rate refuses an amount' => [GoalMetric::SAVINGS_RATE, GoalPeriodicity::MONTHLY, GoalValueType::AMOUNT, GoalScopeType::WORKSPACE, false];
        yield 'savings rate refuses an income share' => [GoalMetric::NET_SAVINGS_RATE, GoalPeriodicity::MONTHLY, GoalValueType::INCOME_SHARE, GoalScopeType::WORKSPACE, false];
        yield 'savings rate is workspace scoped' => [GoalMetric::SAVINGS_RATE, GoalPeriodicity::MONTHLY, GoalValueType::RATE, GoalScopeType::CATEGORY, false];
        yield 'spending refuses a rate' => [GoalMetric::CATEGORY_SPENDING, GoalPeriodicity::MONTHLY, GoalValueType::RATE, GoalScopeType::CATEGORY, false];
        yield 'spending takes an amount yearly' => [GoalMetric::CATEGORY_SPENDING, GoalPeriodicity::YEARLY, GoalValueType::AMOUNT, GoalScopeType::CATEGORY, true];
        yield 'spending takes an income share yearly' => [GoalMetric::AXIS_SPENDING, GoalPeriodicity::YEARLY, GoalValueType::INCOME_SHARE, GoalScopeType::AXIS, true];
        yield 'rolling share is monthly only' => [GoalMetric::BUDGET_SURPLUS, GoalPeriodicity::YEARLY, GoalValueType::ROLLING_INCOME_SHARE, GoalScopeType::WORKSPACE, false];
        yield 'rolling share monthly' => [GoalMetric::BUDGET_SURPLUS, GoalPeriodicity::MONTHLY, GoalValueType::ROLLING_INCOME_SHARE, GoalScopeType::WORKSPACE, true];
        yield 'account contributions monthly amount' => [GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalPeriodicity::MONTHLY, GoalValueType::AMOUNT, GoalScopeType::ACCOUNT, true];
        yield 'account contributions need an account' => [GoalMetric::ACCOUNT_CONTRIBUTIONS, GoalPeriodicity::MONTHLY, GoalValueType::AMOUNT, GoalScopeType::WORKSPACE, false];
        yield 'axis metric needs the axis scope' => [GoalMetric::AXIS_SPENDING, GoalPeriodicity::MONTHLY, GoalValueType::AMOUNT, GoalScopeType::CATEGORY, false];
    }

    #[DataProvider('matrix')]
    public function testTheMetricMatrixAcceptsOnlyCoherentCombinations(GoalMetric $metric, GoalPeriodicity $periodicity, GoalValueType $valueType, GoalScopeType $scope, bool $accepted): void
    {
        self::assertSame($accepted, $metric->accepts($periodicity, $valueType, $scope));
    }

    public function testRateMetricsAreMinimumsAndSpendingMetricsCeilings(): void
    {
        self::assertSame('MINIMUM', GoalMetric::TOTAL_INCOME->direction()->value);
        self::assertSame('CEILING', GoalMetric::TOTAL_SPENDING->direction()->value);
        self::assertSame('MINIMUM', GoalMetric::SAVINGS_RATE->direction()->value);
        self::assertSame('MINIMUM', GoalMetric::NET_SAVINGS_RATE->direction()->value);
        self::assertSame('CEILING', GoalMetric::AXIS_SPENDING->direction()->value);
    }

    public function testPeriodsFollowThePeriodicityFormat(): void
    {
        $this->expectException(InvalidGoal::class);

        self::goal(GoalPeriodicity::MONTHLY, GoalMetric::BUDGET_SURPLUS, GoalScopeType::WORKSPACE, null, '2026', null);
    }

    public function testAYearlyGoalRefusesAMonthPeriod(): void
    {
        $this->expectException(InvalidGoal::class);

        self::goal(GoalPeriodicity::YEARLY, GoalMetric::BUDGET_SURPLUS, GoalScopeType::WORKSPACE, null, '2026-01', null);
    }

    public function testTheEndPeriodNeverPrecedesTheStart(): void
    {
        $this->expectException(InvalidGoal::class);

        self::goal(GoalPeriodicity::MONTHLY, GoalMetric::BUDGET_SURPLUS, GoalScopeType::WORKSPACE, null, '2026-11', '2026-10');
    }

    public function testAOneOffGoalStartsAndEndsOnTheSamePeriod(): void
    {
        $goal = self::goal(GoalPeriodicity::MONTHLY, GoalMetric::BUDGET_SURPLUS, GoalScopeType::WORKSPACE, null, '2026-11', '2026-11');

        self::assertTrue($goal->isCurrent(new CalendarMonth(2026, 11)));
        self::assertFalse($goal->isCurrent(new CalendarMonth(2026, 12)));
    }

    public function testARecurringGoalWithoutEndStaysCurrentAndCoversLaterPeriods(): void
    {
        $goal = self::goal(GoalPeriodicity::MONTHLY, GoalMetric::BUDGET_SURPLUS, GoalScopeType::WORKSPACE, null, '2026-11', null);

        self::assertTrue($goal->isCurrent(new CalendarMonth(2040, 1)));
        self::assertTrue($goal->coversPeriod('2027-03'));
        self::assertTrue($goal->coversPeriod('2027'));
        self::assertFalse($goal->coversPeriod('2026-10'));
        self::assertTrue($goal->coversPeriod('2026'), 'the start year overlaps from November');
        self::assertFalse($goal->coversPeriod('2025'));
    }

    public function testAYearlyGoalCoversItsYearsAndTheirMonths(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, GoalMetric::BUDGET_SURPLUS, GoalScopeType::WORKSPACE, null, '2026', '2027');

        self::assertTrue($goal->coversPeriod('2026'));
        self::assertTrue($goal->coversPeriod('2027-12'));
        self::assertFalse($goal->coversPeriod('2028'));
        self::assertFalse($goal->coversPeriod('2028-01'));
        self::assertFalse($goal->coversPeriod('2025-12'));
        self::assertTrue($goal->isCurrent(new CalendarMonth(2027, 12)));
        self::assertFalse($goal->isCurrent(new CalendarMonth(2028, 1)));
    }

    public function testAMonthlyGoalWithAYearPeriodCoversTheOverlappingYear(): void
    {
        $goal = self::goal(GoalPeriodicity::MONTHLY, GoalMetric::BUDGET_SURPLUS, GoalScopeType::WORKSPACE, null, '2026-03', '2026-05');

        self::assertTrue($goal->coversPeriod('2026'));
        self::assertTrue($goal->coversPeriod('2026-05'));
        self::assertFalse($goal->coversPeriod('2026-06'));
        self::assertFalse($goal->coversPeriod('2027'));
    }

    public function testARateOfZeroIsAMeaningfulFloorButAnIncomeShareOfZeroIsNot(): void
    {
        $rate = GoalVersion::rate('00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09', DecimalValue::fromString('0'), new \DateTimeImmutable('2026-09-01'), self::ACTOR);
        self::assertSame(GoalValueType::RATE, $rate->valueType);
        self::assertSame('0', $rate->ratio?->toString());

        $this->expectException(InvalidGoal::class);
        GoalVersion::share('00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09', GoalValueType::INCOME_SHARE, DecimalValue::fromString('0'), new \DateTimeImmutable('2026-09-01'), self::ACTOR);
    }

    public function testAShareMustBeWithinTheInclusiveUpperBoundAndEightDecimals(): void
    {
        $this->expectException(InvalidGoal::class);

        GoalVersion::share('00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09', GoalValueType::ROLLING_INCOME_SHARE, DecimalValue::fromString('1.000000001'), new \DateTimeImmutable('2026-09-01'), self::ACTOR);
    }

    public function testAShareJustAboveOneIsRefusedAndOneItselfIsAccepted(): void
    {
        $one = GoalVersion::share('00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09', GoalValueType::INCOME_SHARE, DecimalValue::fromString('1'), new \DateTimeImmutable('2026-09-01'), self::ACTOR);
        self::assertSame('1', $one->ratio?->toString());

        $this->expectException(InvalidGoal::class);
        GoalVersion::rate('00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09', DecimalValue::fromString('1.00000001'), new \DateTimeImmutable('2026-09-01'), self::ACTOR);
    }

    public function testAShareValueTypeCannotCarryTheRateType(): void
    {
        $this->expectException(InvalidGoal::class);

        GoalVersion::share('00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09', GoalValueType::RATE, DecimalValue::fromString('0.5'), new \DateTimeImmutable('2026-09-01'), self::ACTOR);
    }

    public function testAnAmountVersionRefusesAScheduleBuiltForAnotherTarget(): void
    {
        $this->expectException(InvalidGoal::class);

        GoalVersion::amount(
            '00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09',
            DecimalValue::fromString('1200.00'), GoalSchedule::linear(DecimalValue::fromString('1000'), 2),
            new \DateTimeImmutable('2026-09-01'), self::ACTOR,
        );
    }

    public function testAVersionIsStrictByDefaultAndKeepsAToleranceThroughAMove(): void
    {
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        $strict = GoalVersion::rate('00000000-0000-7000-8000-000000000004', $workspace, '00000000-0000-7000-8000-000000000001', 1, '2026-09', DecimalValue::fromString('0.15'), new \DateTimeImmutable('2026-09-01'), self::ACTOR);
        $tolerant = GoalVersion::rate('00000000-0000-7000-8000-000000000004', $workspace, '00000000-0000-7000-8000-000000000001', 1, '2026-09', DecimalValue::fromString('0.15'), new \DateTimeImmutable('2026-09-01'), self::ACTOR, tolerance: DecimalValue::fromString('0.01'));

        self::assertSame('0', $strict->tolerance->toString());
        self::assertSame('0.01', $tolerant->tolerance->toString());
        self::assertSame('0.01', $tolerant->startingAt('2026-10')->tolerance->toString());
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptedTolerances(): iterable
    {
        yield 'zero' => ['1200.00', '0'];
        yield 'equal to the target' => ['1200.00', '1200.00'];
        yield 'equal across scales' => ['1200.00', '1200.000'];
        yield 'huge' => ['99999999999999999999999999.99999999', '99999999999999999999999999.99999999'];
    }

    #[DataProvider('acceptedTolerances')]
    public function testAnAmountToleranceBetweenZeroAndTheTargetIsAccepted(string $target, string $tolerance): void
    {
        $version = GoalVersion::amount('00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09', DecimalValue::fromString($target), null, new \DateTimeImmutable('2026-09-01'), self::ACTOR, tolerance: DecimalValue::fromString($tolerance));

        self::assertSame($tolerance, $version->tolerance->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function refusedTolerances(): iterable
    {
        yield 'negative' => ['-0.01'];
        yield 'above the target' => ['1200.01'];
        yield 'nine decimals' => ['0.123456789'];
    }

    #[DataProvider('refusedTolerances')]
    public function testAnAmountToleranceOutsideItsBoundsIsRefused(string $tolerance): void
    {
        $this->expectException(InvalidGoal::class);
        GoalVersion::amount('00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09', DecimalValue::fromString('1200.00'), null, new \DateTimeImmutable('2026-09-01'), self::ACTOR, tolerance: DecimalValue::fromString($tolerance));
    }

    public function testARatioToleranceCannotExceedTheRatio(): void
    {
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        $ok = GoalVersion::share('00000000-0000-7000-8000-000000000004', $workspace, '00000000-0000-7000-8000-000000000001', 1, '2026-09', GoalValueType::INCOME_SHARE, DecimalValue::fromString('0.3'), new \DateTimeImmutable('2026-09-01'), self::ACTOR, tolerance: DecimalValue::fromString('0.3'));
        self::assertSame('0.3', $ok->tolerance->toString());

        $this->expectException(InvalidGoal::class);
        GoalVersion::rate('00000000-0000-7000-8000-000000000004', $workspace, '00000000-0000-7000-8000-000000000001', 1, '2026-09', DecimalValue::fromString('0.15'), new \DateTimeImmutable('2026-09-01'), self::ACTOR, tolerance: DecimalValue::fromString('0.16'));
    }

    public function testAShareToleranceConvertsToAnAmountWithTheIncomeOfItsTarget(): void
    {
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        // 30 % of 4000.00 is 1200.00; a margin of 1.25 points is 50.000000 of the same income.
        $share = GoalVersion::share('00000000-0000-7000-8000-000000000004', $workspace, '00000000-0000-7000-8000-000000000001', 1, '2026-09', GoalValueType::INCOME_SHARE, DecimalValue::fromString('0.3'), new \DateTimeImmutable('2026-09-01'), self::ACTOR, tolerance: DecimalValue::fromString('0.0125'));
        $strict = GoalVersion::share('00000000-0000-7000-8000-000000000004', $workspace, '00000000-0000-7000-8000-000000000001', 1, '2026-09', GoalValueType::INCOME_SHARE, DecimalValue::fromString('0.3'), new \DateTimeImmutable('2026-09-01'), self::ACTOR);
        $rate = GoalVersion::rate('00000000-0000-7000-8000-000000000004', $workspace, '00000000-0000-7000-8000-000000000001', 1, '2026-09', DecimalValue::fromString('0.15'), new \DateTimeImmutable('2026-09-01'), self::ACTOR, tolerance: DecimalValue::fromString('0.01'));

        self::assertSame('50.000000', $share->toleranceAmount('4000.00'));
        self::assertNull($share->toleranceAmount(null));
        self::assertNull($strict->toleranceAmount('4000.00'));
        self::assertSame('0.01', $rate->toleranceAmount(null));
    }

    public function testAShareVersionHasNoSchedule(): void
    {
        $definition = GoalVersion::share('00000000-0000-7000-8000-000000000004', WorkspaceScope::fromString(self::WORKSPACE), '00000000-0000-7000-8000-000000000001', 1, '2026-09', GoalValueType::ROLLING_INCOME_SHARE, DecimalValue::fromString('0.1'), new \DateTimeImmutable('2026-09-01'), self::ACTOR);

        self::assertNull($definition->schedule);
        self::assertNull($definition->scheduleMode());
        self::assertNull($definition->targetAmount);
    }

    private static function goal(GoalPeriodicity $periodicity, GoalMetric $metric, GoalScopeType $scope, ?string $scopeRef, string $start, ?string $end): Goal
    {
        return new Goal(
            '00000000-0000-7000-8000-000000000001', WorkspaceScope::fromString(self::WORKSPACE), 'Goal', $periodicity, $metric, $scope, $scopeRef,
            AssetCode::fromString('EUR'), $start, $end, 1, new \DateTimeImmutable('2026-09-01'), self::ACTOR,
        );
    }
}
