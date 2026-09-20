<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface BudgetTargetRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?BudgetTarget;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?BudgetTarget;

    /** @return list<BudgetTarget> at most $limit targets of $planId, in this workspace */
    public function listByPlan(WorkspaceScope $workspace, string $planId, int $limit): array;

    public function countByPlan(WorkspaceScope $workspace, string $planId): int;

    public function add(BudgetTarget $target): void;

    /** Returns false when the expected version is stale. */
    public function update(BudgetTarget $target, int $expectedVersion): bool;

    /** Returns false when the expected version is stale. */
    public function remove(BudgetTarget $target, int $expectedVersion): bool;
}
