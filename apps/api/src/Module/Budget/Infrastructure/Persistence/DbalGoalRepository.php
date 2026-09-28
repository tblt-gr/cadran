<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(GoalRepository::class)]
final readonly class DbalGoalRepository implements GoalRepository
{
    private const string COLUMNS = 'id, workspace_id, label, kind, metric, scope_type, scope_ref, asset_code, state, created_at, created_by, closed_at, version';

    public function __construct(private Connection $connection)
    {
    }

    public function lockWorkspace(WorkspaceScope $workspace): void
    {
        $this->connection->fetchOne('SELECT id FROM identity_workspaces WHERE id = :workspace_id FOR UPDATE', ['workspace_id' => $workspace->id]);
    }

    public function find(WorkspaceScope $workspace, string $id): ?Goal
    {
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM budget_goals WHERE workspace_id = :workspace_id AND id = :id', ['workspace_id' => $workspace->id, 'id' => $id]);

        return false === $row ? null : GoalRow::hydrate($row, $workspace);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Goal
    {
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM budget_goals WHERE workspace_id = :workspace_id AND id = :id FOR UPDATE', ['workspace_id' => $workspace->id, 'id' => $id]);

        return false === $row ? null : GoalRow::hydrate($row, $workspace);
    }

    public function listForYear(WorkspaceScope $workspace, int $year, GoalState $state, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT g.'.str_replace(', ', ', g.', self::COLUMNS)." FROM budget_goals g JOIN budget_goal_versions v ON v.workspace_id = :workspace_id AND v.goal_id = g.id WHERE g.workspace_id = :workspace_id AND g.state = :state AND ((g.kind = 'ANNUAL' AND v.year = :year) OR (g.kind = 'ROLLING_RATIO' AND v.effective_from <= :year_end)) ORDER BY g.created_at, g.id LIMIT :limit",
            ['workspace_id' => $workspace->id, 'state' => $state->value, 'year' => $year, 'year_end' => sprintf('%04d-12', $year), 'limit' => $limit],
            ['year' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): Goal => GoalRow::hydrate($row, $workspace), $rows);
    }

    public function countActive(WorkspaceScope $workspace): int
    {
        return (int) GoalRow::text($this->connection->fetchOne("SELECT count(*) FROM budget_goals WHERE workspace_id = :workspace_id AND state = 'ACTIVE'", ['workspace_id' => $workspace->id]));
    }

    public function add(Goal $goal): void
    {
        $this->connection->insert('budget_goals', ['id' => $goal->id, 'workspace_id' => $goal->workspace->id, ...GoalRow::columns($goal), 'created_at' => $goal->createdAt->format('Y-m-d H:i:s.uP'), 'created_by' => $goal->createdBy]);
    }

    public function update(Goal $goal, int $expectedVersion): bool
    {
        return 1 === (int) $this->connection->update('budget_goals', GoalRow::columns($goal), ['workspace_id' => $goal->workspace->id, 'id' => $goal->id, 'version' => $expectedVersion]);
    }
}
