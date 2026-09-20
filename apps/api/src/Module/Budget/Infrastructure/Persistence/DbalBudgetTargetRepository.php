<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetTargetRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(BudgetTargetRepository::class)]
final readonly class DbalBudgetTargetRepository implements BudgetTargetRepository
{
    private const string COLUMNS = 'id, workspace_id, plan_id, scope_type, scope_id, value_type, amount_value, amount_scale, ratio_value, ratio_scale, version, created_at, updated_at';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?BudgetTarget
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM budget_targets WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : BudgetTargetRow::hydrate($row, $workspace);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?BudgetTarget
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM budget_targets WHERE workspace_id = :workspace_id AND id = :id FOR UPDATE',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : BudgetTargetRow::hydrate($row, $workspace);
    }

    public function listByPlan(WorkspaceScope $workspace, string $planId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM budget_targets WHERE workspace_id = :workspace_id AND plan_id = :plan_id ORDER BY created_at, id LIMIT :limit',
            ['workspace_id' => $workspace->id, 'plan_id' => $planId, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): BudgetTarget => BudgetTargetRow::hydrate($row, $workspace), $rows);
    }

    public function countByPlan(WorkspaceScope $workspace, string $planId): int
    {
        return (int) BudgetTargetRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM budget_targets WHERE workspace_id = :workspace_id AND plan_id = :plan_id',
            ['workspace_id' => $workspace->id, 'plan_id' => $planId],
        ));
    }

    public function add(BudgetTarget $target): void
    {
        $this->connection->insert('budget_targets', [
            'id' => $target->id,
            'workspace_id' => $target->workspace->id,
            ...BudgetTargetRow::columns($target),
            'created_at' => $target->createdAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function update(BudgetTarget $target, int $expectedVersion): bool
    {
        return 1 === (int) $this->connection->update(
            'budget_targets',
            BudgetTargetRow::columns($target),
            ['workspace_id' => $target->workspace->id, 'id' => $target->id, 'version' => $expectedVersion],
        );
    }

    public function remove(BudgetTarget $target, int $expectedVersion): bool
    {
        return 1 === (int) $this->connection->delete('budget_targets', [
            'workspace_id' => $target->workspace->id,
            'id' => $target->id,
            'version' => $expectedVersion,
        ]);
    }
}
