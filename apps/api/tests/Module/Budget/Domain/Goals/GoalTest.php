<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain\Goals;

use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalKind;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\InvalidGoal;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\TestCase;

final class GoalTest extends TestCase
{
    public function testAnAnnualGoalDerivesItsDirectionAndKeepsDefinitionsInVersions(): void
    {
        $goal = new Goal(
            '00000000-0000-7000-8000-000000000001',
            WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            'Holiday cap',
            GoalKind::ANNUAL,
            GoalMetric::CATEGORY_SPENDING,
            GoalScopeType::CATEGORY,
            '00000000-0000-7000-8000-000000000002',
            AssetCode::fromString('EUR'),
            GoalState::ACTIVE,
            1,
            new \DateTimeImmutable('2026-09-01'),
            '00000000-0000-7000-8000-000000000003',
        );
        $definition = GoalVersion::annual(
            '00000000-0000-7000-8000-000000000004',
            $goal->workspace,
            $goal->id,
            1,
            '2026-09',
            2026,
            DecimalValue::fromString('1200.00'),
            GoalSchedule::linear(DecimalValue::fromString('1200.00'), 2),
            new \DateTimeImmutable('2026-09-01'),
            '00000000-0000-7000-8000-000000000003',
        );

        self::assertSame('CEILING', $goal->direction()->value);
        self::assertSame('LINEAR', $definition->scheduleMode());
        self::assertSame('1200.00', $definition->targetAmount?->toString());
        self::assertNull($definition->ratio);
    }

    public function testARollingRatioMustBeWithinTheInclusiveUpperBoundAndEightDecimals(): void
    {
        $this->expectException(InvalidGoal::class);

        GoalVersion::rolling(
            '00000000-0000-7000-8000-000000000004',
            WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            '00000000-0000-7000-8000-000000000001',
            1,
            '2026-09',
            DecimalValue::fromString('1.000000001'),
            new \DateTimeImmutable('2026-09-01'),
            '00000000-0000-7000-8000-000000000003',
        );
    }

    public function testMetricScopeAndKindAreValidatedTogether(): void
    {
        $this->expectException(InvalidGoal::class);

        new Goal(
            '00000000-0000-7000-8000-000000000001',
            WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            'Invalid',
            GoalKind::ROLLING_RATIO,
            GoalMetric::BUDGET_SURPLUS,
            GoalScopeType::WORKSPACE,
            null,
            AssetCode::fromString('EUR'),
            GoalState::ACTIVE,
            1,
            new \DateTimeImmutable('2026-09-01'),
            '00000000-0000-7000-8000-000000000003',
        );
    }

    public function testAnAnnualVersionRefusesAScheduleBuiltForAnotherTarget(): void
    {
        $this->expectException(InvalidGoal::class);

        GoalVersion::annual(
            '00000000-0000-7000-8000-000000000004',
            WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            '00000000-0000-7000-8000-000000000001',
            1,
            '2026-09',
            2026,
            DecimalValue::fromString('1200.00'),
            GoalSchedule::linear(DecimalValue::fromString('1000'), 2),
            new \DateTimeImmutable('2026-09-01'),
            '00000000-0000-7000-8000-000000000003',
        );
    }

    public function testARollingVersionHasNoSchedule(): void
    {
        $definition = GoalVersion::rolling(
            '00000000-0000-7000-8000-000000000004',
            WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            '00000000-0000-7000-8000-000000000001',
            1,
            '2026-09',
            DecimalValue::fromString('0.1'),
            new \DateTimeImmutable('2026-09-01'),
            '00000000-0000-7000-8000-000000000003',
        );

        self::assertNull($definition->schedule);
        self::assertNull($definition->scheduleMode());
    }
}
