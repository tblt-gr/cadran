<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain\Goals;

use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalDirection;
use App\Module\Budget\Domain\Goals\GoalEvaluationRules;
use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalPrecedence;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\InvalidGoal;
use App\Module\Foundation\Domain\AssetCode;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoalEvaluationRulesTest extends TestCase
{
    /** @return iterable<string, array{GoalDirection, ?string, ?string, bool, GoalEvaluationStatus}> */
    public static function matrix(): iterable
    {
        $c = GoalDirection::CEILING;
        $m = GoalDirection::MINIMUM;
        yield 'ceiling exceeded while running' => [$c, '1210.00', '1200.00', false, GoalEvaluationStatus::MISSED];
        yield 'ceiling respected while running' => [$c, '1100.00', '1200.00', false, GoalEvaluationStatus::IN_PROGRESS];
        yield 'ceiling respected when complete' => [$c, '1100.00', '1200.00', true, GoalEvaluationStatus::MET];
        yield 'ceiling equality is met' => [$c, '1200.00', '1200.00', true, GoalEvaluationStatus::MET];
        yield 'ceiling equality across scales' => [$c, '1200', '1200.000000000000000000000000', true, GoalEvaluationStatus::MET];
        yield 'ceiling one unit of the last place over' => [$c, '1200.000000000000000000000001', '1200', true, GoalEvaluationStatus::MISSED];
        yield 'minimum short while running' => [$m, '0.04', '0.10', false, GoalEvaluationStatus::IN_PROGRESS];
        yield 'minimum short when complete' => [$m, '0.09', '0.10', true, GoalEvaluationStatus::MISSED];
        yield 'minimum equality is met' => [$m, '0.10', '0.10', true, GoalEvaluationStatus::MET];
        yield 'minimum reached while running' => [$m, '0.20', '0.10', false, GoalEvaluationStatus::IN_PROGRESS];
        yield 'zero achieved minimum complete' => [$m, '0', '100.00', true, GoalEvaluationStatus::MISSED];
        yield 'zero achieved ceiling complete' => [$c, '0', '100.00', true, GoalEvaluationStatus::MET];
        yield 'huge values' => [$m, '99999999999999999999999999.999999999999999999999999', '99999999999999999999999999.999999999999999999999998', true, GoalEvaluationStatus::MET];
        yield 'null achieved' => [$c, null, '100.00', true, GoalEvaluationStatus::NOT_CALCULABLE];
        yield 'null target' => [$m, '100.00', null, true, GoalEvaluationStatus::NOT_CALCULABLE];
    }

    #[DataProvider('matrix')]
    public function testTheStatusMatrix(GoalDirection $direction, ?string $achieved, ?string $target, bool $complete, GoalEvaluationStatus $expected): void
    {
        self::assertSame($expected, GoalEvaluationRules::status($direction, $achieved, $target, $complete));
    }

    /** @return iterable<string, array{GoalDirection, ?string, string, string, bool, GoalEvaluationStatus, ?bool}> */
    public static function toleranceMatrix(): iterable
    {
        $c = GoalDirection::CEILING;
        $m = GoalDirection::MINIMUM;
        yield 'ceiling inside the margin' => [$c, '1240.00', '1200.00', '50.00', true, GoalEvaluationStatus::MET, true];
        yield 'ceiling at the bound is met' => [$c, '1250.00', '1200.00', '50.00', true, GoalEvaluationStatus::MET, true];
        yield 'ceiling one cent past the bound' => [$c, '1250.01', '1200.00', '50.00', true, GoalEvaluationStatus::MISSED, false];
        yield 'ceiling within the target is not within tolerance' => [$c, '1100.00', '1200.00', '50.00', true, GoalEvaluationStatus::MET, false];
        yield 'ceiling at the target is not within tolerance' => [$c, '1200.00', '1200.00', '50.00', true, GoalEvaluationStatus::MET, false];
        yield 'running ceiling inside the margin' => [$c, '1240.00', '1200.00', '50.00', false, GoalEvaluationStatus::IN_PROGRESS, true];
        yield 'running ceiling past the bound' => [$c, '1250.01', '1200.00', '50.00', false, GoalEvaluationStatus::MISSED, false];
        yield 'minimum rate inside the margin' => [$m, '0.145', '0.15', '0.01', true, GoalEvaluationStatus::MET, true];
        yield 'minimum rate at the bound is met' => [$m, '0.14', '0.15', '0.01', true, GoalEvaluationStatus::MET, true];
        yield 'minimum rate below the bound' => [$m, '0.139', '0.15', '0.01', true, GoalEvaluationStatus::MISSED, false];
        yield 'minimum reached is not within tolerance' => [$m, '0.15', '0.15', '0.01', true, GoalEvaluationStatus::MET, false];
        yield 'running minimum inside the margin' => [$m, '760.00', '800.00', '50.00', false, GoalEvaluationStatus::IN_PROGRESS, true];
        yield 'running minimum below the bound' => [$m, '740.00', '800.00', '50.00', false, GoalEvaluationStatus::IN_PROGRESS, false];
        yield 'tolerance equal to the target keeps a zero minimum met' => [$m, '0', '100.00', '100.00', true, GoalEvaluationStatus::MET, true];
        yield 'huge values at the bound' => [$c, '100000000000000000000000000.000000000000000000000000', '99999999999999999999999999.000000000000000000000000', '1', true, GoalEvaluationStatus::MET, true];
        yield 'huge values one unit past the bound' => [$c, '100000000000000000000000000.000000000000000000000001', '99999999999999999999999999.000000000000000000000000', '1', true, GoalEvaluationStatus::MISSED, false];
    }

    #[DataProvider('toleranceMatrix')]
    public function testToleranceWidensTheBoundOfTheStatus(GoalDirection $direction, string $achieved, string $target, string $tolerance, bool $complete, GoalEvaluationStatus $expected, bool $within): void
    {
        self::assertSame($expected, GoalEvaluationRules::status($direction, $achieved, $target, $complete, $tolerance));
        self::assertSame($within, GoalEvaluationRules::withinTolerance($direction, $achieved, $target, $tolerance));
    }

    public function testWithoutToleranceTheResultsAreStrict(): void
    {
        foreach (['0', null] as $tolerance) {
            self::assertSame(GoalEvaluationStatus::MISSED, GoalEvaluationRules::status(GoalDirection::CEILING, '1200.01', '1200.00', true, $tolerance));
            self::assertSame(GoalEvaluationStatus::MISSED, GoalEvaluationRules::status(GoalDirection::MINIMUM, '0.149', '0.15', true, $tolerance));
            self::assertFalse(GoalEvaluationRules::withinTolerance(GoalDirection::CEILING, '1200.01', '1200.00', $tolerance));
            self::assertFalse(GoalEvaluationRules::withinTolerance(GoalDirection::MINIMUM, '0.149', '0.15', $tolerance));
        }
    }

    public function testToleranceIsNotCalculableWithoutAFigure(): void
    {
        self::assertNull(GoalEvaluationRules::withinTolerance(GoalDirection::CEILING, null, '1200.00', '50.00'));
        self::assertNull(GoalEvaluationRules::withinTolerance(GoalDirection::MINIMUM, '1.00', null, '50.00'));
        self::assertSame(GoalEvaluationStatus::NOT_CALCULABLE, GoalEvaluationRules::status(GoalDirection::MINIMUM, null, '1.00', true, '0.50'));
    }

    public function testTheGapIsPositiveInTheGoalsFavour(): void
    {
        self::assertSame('-50.00', GoalEvaluationRules::gap(GoalDirection::CEILING, '1250.00', '1200.00'));
        self::assertSame('100.00', GoalEvaluationRules::gap(GoalDirection::CEILING, '1100.00', '1200.00'));
        self::assertSame('-0.01', GoalEvaluationRules::gap(GoalDirection::MINIMUM, '0.09', '0.10'));
        self::assertSame('0.00', GoalEvaluationRules::gap(GoalDirection::MINIMUM, '0.10', '0.10'));
        self::assertNull(GoalEvaluationRules::gap(GoalDirection::MINIMUM, null, '0.10'));
        self::assertNull(GoalEvaluationRules::gap(GoalDirection::CEILING, '1.00', null));
    }

    public function testPace(): void
    {
        self::assertSame('BEHIND', GoalEvaluationRules::pace(GoalDirection::MINIMUM, '5100.00', '5500.00'));
        self::assertSame('AHEAD', GoalEvaluationRules::pace(GoalDirection::MINIMUM, '5500.00', '5500.00'));
        self::assertSame('ON_TRACK', GoalEvaluationRules::pace(GoalDirection::CEILING, '5500.00', '5500.00'));
        self::assertSame('OVER_PACE', GoalEvaluationRules::pace(GoalDirection::CEILING, '5500.01', '5500.00'));
    }

    public function testStockDirectionSurvivesRenamingAndStopping(): void
    {
        $goal = new Goal('00000000-0000-7000-8000-000000000001', WorkspaceFixture::own(), 'Debt cap', GoalPeriodicity::MONTHLY, GoalMetric::ACCOUNT_BALANCE, GoalScopeType::ACCOUNT, '00000000-0000-7000-8000-000000000002', AssetCode::fromString('EUR'), '2026-01', null, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID, GoalDirection::CEILING);
        self::assertSame(GoalDirection::CEILING, $goal->stoppedAt('2026-03')->direction());
        self::assertSame(GoalDirection::CEILING, $goal->reviseLabel('Loan cap')->direction());
    }

    public function testStockPaceInterpolatesFromTheOpeningValue(): void
    {
        $expected = GoalEvaluationRules::stockExpected('10000', '20000', 3);
        self::assertNotNull($expected);
        self::assertSame(0, \App\Module\Foundation\Domain\ExactDecimal::compareForResponse('12500', $expected));
        self::assertSame('BEHIND', GoalEvaluationRules::pace(GoalDirection::MINIMUM, '12000', $expected));
        self::assertSame(0, \App\Module\Foundation\Domain\ExactDecimal::compareForResponse('750', GoalEvaluationRules::stockExpected('1000', '0', 3) ?? throw new \LogicException('Expected a calculable pace.')));
        self::assertNull(GoalEvaluationRules::stockExpected(null, '20000', 3));
        self::assertNull(GoalEvaluationRules::stockExpected('10000', null, 3));
        self::assertSame(0, \App\Module\Foundation\Domain\ExactDecimal::compareForResponse('0', GoalEvaluationRules::stockExpected('0', '10000', 0) ?? throw new \LogicException('Expected a calculable pace.')));
        self::assertSame(0, \App\Module\Foundation\Domain\ExactDecimal::compareForResponse('99999999999999999999999999', GoalEvaluationRules::stockExpected('99999999999999999999999998', '99999999999999999999999999', 12) ?? throw new \LogicException('Expected a calculable pace.')));
        self::assertSame(0, \App\Module\Foundation\Domain\ExactDecimal::compareForResponse('0', GoalEvaluationRules::stockExpected('-1000', '1000', 6) ?? throw new \LogicException('Expected a calculable pace.')));
    }

    public function testTheLatestStartThenTheLatestCreationWinsAndHidesTheOthers(): void
    {
        $old = self::goal('00000000-0000-7000-8000-000000000001', '2026-01', null, '2026-01-01');
        $later = self::goal('00000000-0000-7000-8000-000000000002', '2026-08', '2026-08', '2026-01-02');
        $sameStartEarlier = self::goal('00000000-0000-7000-8000-000000000003', '2026-08', null, '2026-02-01');
        $sameStartLater = self::goal('00000000-0000-7000-8000-000000000004', '2026-08', null, '2026-03-01');
        $yearly = new Goal('00000000-0000-7000-8000-000000000005', WorkspaceFixture::own(), 'Y', GoalPeriodicity::YEARLY, GoalMetric::AXIS_SPENDING, GoalScopeType::AXIS, 'ESSENTIAL', AssetCode::fromString('EUR'), '2026', null, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);

        $july = GoalPrecedence::winners([$old, $later, $sameStartEarlier], '2026-07');
        self::assertSame([$old->id], array_map(static fn (array $w): string => $w['goal']->id, $july));
        self::assertSame([], $july[0]['overrides']);

        $august = GoalPrecedence::winners([$old, $later, $sameStartEarlier, $sameStartLater], '2026-08');
        self::assertCount(1, $august);
        self::assertSame($sameStartLater->id, $august[0]['goal']->id);
        self::assertSame([$old->id, $later->id, $sameStartEarlier->id], $august[0]['overrides']);

        self::assertSame([], GoalPrecedence::winners([$old, $later, $yearly], '2025-12'));
        self::assertCount(1, GoalPrecedence::winners([$yearly], '2026'));
    }

    public function testAStopNeverExtendsOrPrecedesAGoal(): void
    {
        $goal = self::goal('00000000-0000-7000-8000-000000000001', '2026-03', '2026-09', '2026-01-01');

        $stopped = $goal->stoppedAt('2026-05');
        self::assertSame('2026-05', $stopped->endPeriod);
        self::assertSame(2, $stopped->version);
        $refused = 0;
        foreach (['2026-02', '2026-10', '2026', 'x'] as $bad) {
            try {
                $goal->stoppedAt($bad);
            } catch (InvalidGoal) {
                ++$refused;
            }
        }
        self::assertSame(4, $refused);
        self::assertSame('2026-03', $goal->stoppedAt('2026-03')->endPeriod);
    }

    private static function goal(string $id, string $start, ?string $end, string $created): Goal
    {
        return new Goal($id, WorkspaceFixture::own(), 'G', GoalPeriodicity::MONTHLY, GoalMetric::AXIS_SPENDING, GoalScopeType::AXIS, 'ESSENTIAL', AssetCode::fromString('EUR'), $start, $end, 1, new \DateTimeImmutable($created), WorkspaceFixture::OWNER_ID);
    }
}
