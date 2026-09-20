<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Double;

use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetTargetRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

final class InMemoryBudgetTargetRepository implements BudgetTargetRepository
{
    /** @var array<string, BudgetTarget> */
    private array $targets = [];
    private bool $failRemove = false;

    public function find(WorkspaceScope $workspace, string $id): ?BudgetTarget
    {
        $target = $this->targets[$id] ?? null;

        return null !== $target && $target->workspace->equals($workspace) ? $target : null;
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?BudgetTarget
    {
        return $this->find($workspace, $id);
    }

    public function listByPlan(WorkspaceScope $workspace, string $planId, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->targets,
            static fn (BudgetTarget $target): bool => $target->workspace->equals($workspace) && $target->planId === $planId,
        )), 0, $limit);
    }

    public function countByPlan(WorkspaceScope $workspace, string $planId): int
    {
        return count(array_filter(
            $this->targets,
            static fn (BudgetTarget $target): bool => $target->workspace->equals($workspace) && $target->planId === $planId,
        ));
    }

    public function add(BudgetTarget $target): void
    {
        $this->targets[$target->id] = $target;
    }

    public function update(BudgetTarget $target, int $expectedVersion): bool
    {
        $current = $this->targets[$target->id] ?? null;
        if (null === $current || $current->version !== $expectedVersion) {
            return false;
        }

        $this->targets[$target->id] = $target;

        return true;
    }

    public function remove(BudgetTarget $target, int $expectedVersion): bool
    {
        if ($this->failRemove || ($this->targets[$target->id] ?? null)?->version !== $expectedVersion) {
            $this->failRemove = false;

            return false;
        }
        unset($this->targets[$target->id]);

        return true;
    }

    public function failNextRemove(): void
    {
        $this->failRemove = true;
    }
}
