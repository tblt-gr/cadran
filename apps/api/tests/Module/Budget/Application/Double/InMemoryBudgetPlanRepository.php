<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Double;

use App\Module\Budget\Domain\BudgetPeriodType;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Foundation\Domain\WorkspaceScope;

final class InMemoryBudgetPlanRepository implements BudgetPlanRepository
{
    /** @var array<string, BudgetPlan> */
    private array $plans = [];

    public function find(WorkspaceScope $workspace, string $id): ?BudgetPlan
    {
        $plan = $this->plans[$id] ?? null;

        return null !== $plan && $plan->workspace->equals($workspace) ? $plan : null;
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?BudgetPlan
    {
        return $this->find($workspace, $id);
    }

    public function findByPeriod(WorkspaceScope $workspace, BudgetPeriodType $periodType, \DateTimeImmutable $period): ?BudgetPlan
    {
        foreach ($this->plans as $plan) {
            if ($plan->workspace->equals($workspace)
                && $plan->period->type === $periodType
                && $plan->period->firstDay()->format('Y-m-d') === $period->format('Y-m-d')) {
                return $plan;
            }
        }

        return null;
    }

    public function findActiveByPeriod(WorkspaceScope $workspace, BudgetPeriodType $periodType, \DateTimeImmutable $period): ?BudgetPlan
    {
        foreach ($this->plans as $plan) {
            if ($plan->workspace->equals($workspace)
                && $plan->period->type === $periodType
                && $plan->period->firstDay()->format('Y-m-d') === $period->format('Y-m-d')
                && BudgetPlanState::ACTIVE === $plan->state) {
                return $plan;
            }
        }

        return null;
    }

    public function list(WorkspaceScope $workspace, int $limit, int $offset): array
    {
        $plans = array_values(array_filter($this->plans, static fn (BudgetPlan $plan): bool => $plan->workspace->equals($workspace)));

        return array_slice($plans, $offset, $limit);
    }

    public function count(WorkspaceScope $workspace): int
    {
        return count(array_filter($this->plans, static fn (BudgetPlan $plan): bool => $plan->workspace->equals($workspace)));
    }

    public function add(BudgetPlan $plan): void
    {
        $this->plans[$plan->id] = $plan;
    }

    public function update(BudgetPlan $plan, int $expectedVersion): bool
    {
        $current = $this->plans[$plan->id] ?? null;
        if (null === $current || $current->version !== $expectedVersion) {
            return false;
        }

        $this->plans[$plan->id] = $plan;

        return true;
    }
}
