<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface BudgetPlanRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?BudgetPlan;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?BudgetPlan;

    public function findByPeriod(WorkspaceScope $workspace, BudgetPeriodType $periodType, \DateTimeImmutable $period): ?BudgetPlan;

    public function findActiveByPeriod(WorkspaceScope $workspace, BudgetPeriodType $periodType, \DateTimeImmutable $period): ?BudgetPlan;

    /** @return list<BudgetPlan> */
    public function list(WorkspaceScope $workspace, int $limit, int $offset): array;

    public function count(WorkspaceScope $workspace): int;

    public function add(BudgetPlan $plan): void;

    /** Returns false when the expected version is stale. */
    public function update(BudgetPlan $plan, int $expectedVersion): bool;
}
