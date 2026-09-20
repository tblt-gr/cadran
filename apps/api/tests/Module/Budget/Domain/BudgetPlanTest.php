<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain;

use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Budget\Domain\InvalidBudgetPlan;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\TestCase;

final class BudgetPlanTest extends TestCase
{
    private const string WORKSPACE_ID = '11111111-1111-4111-8111-111111111111';
    private const string PLAN_ID = '22222222-2222-4222-8222-222222222222';

    public function testADraftPlanCarriesItsPeriodStateAndCurrency(): void
    {
        $plan = $this->plan();

        self::assertSame(BudgetPlanState::DRAFT, $plan->state);
        self::assertSame('EUR', $plan->assetCode->toString());
        self::assertSame('2026-09', $plan->period->key());
        self::assertSame(1, $plan->version);
    }

    public function testActivatingADraftPlanMovesItToActiveAndBumpsVersion(): void
    {
        $plan = $this->plan();

        $active = $plan->activate(new \DateTimeImmutable('2026-09-01T00:00:00+00:00'));

        self::assertSame(BudgetPlanState::ACTIVE, $active->state);
        self::assertSame(2, $active->version);
    }

    public function testActivatingAnAlreadyActivePlanIsRefused(): void
    {
        $plan = $this->plan()->activate(new \DateTimeImmutable());

        $this->expectException(InvalidBudgetPlan::class);

        $plan->activate(new \DateTimeImmutable());
    }

    public function testActivatingAClosedPlanIsRefused(): void
    {
        $plan = $this->plan()->activate(new \DateTimeImmutable())->close(new \DateTimeImmutable());

        $this->expectException(InvalidBudgetPlan::class);

        $plan->activate(new \DateTimeImmutable());
    }

    public function testClosingADraftPlanIsRefused(): void
    {
        $plan = $this->plan();

        $this->expectException(InvalidBudgetPlan::class);

        $plan->close(new \DateTimeImmutable());
    }

    public function testClosingAnActivePlanMovesItToClosedAndBumpsVersion(): void
    {
        $active = $this->plan()->activate(new \DateTimeImmutable());

        $closed = $active->close(new \DateTimeImmutable('2026-10-01T00:00:00+00:00'));

        self::assertSame(BudgetPlanState::CLOSED, $closed->state);
        self::assertSame(3, $closed->version);
    }

    public function testClosingAnAlreadyClosedPlanIsRefused(): void
    {
        $plan = $this->plan()->activate(new \DateTimeImmutable())->close(new \DateTimeImmutable());

        $this->expectException(InvalidBudgetPlan::class);

        $plan->close(new \DateTimeImmutable());
    }

    private function plan(): BudgetPlan
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new BudgetPlan(
            id: self::PLAN_ID,
            workspace: WorkspaceScope::fromString(self::WORKSPACE_ID),
            period: BudgetPeriod::month(2026, 9),
            assetCode: AssetCode::fromString('EUR'),
            state: BudgetPlanState::DRAFT,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
