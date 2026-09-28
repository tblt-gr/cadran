<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(GoalVersionRepository::class)]
final readonly class DbalGoalVersionRepository implements GoalVersionRepository
{
    private const string COLUMNS = 'id, workspace_id, goal_id, version_no, effective_from, year, target_amount, target_scale, ratio, ratio_scale, schedule_mode, reason, created_at, created_by';

    public function __construct(private Connection $connection)
    {
    }

    public function listByGoal(WorkspaceScope $workspace, string $goalId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM budget_goal_versions WHERE workspace_id = :workspace_id AND goal_id = :goal_id ORDER BY version_no DESC LIMIT :limit', ['workspace_id' => $workspace->id, 'goal_id' => $goalId, 'limit' => $limit], ['limit' => ParameterType::INTEGER]);

        return array_map(static fn (array $row): GoalVersion => GoalVersionRow::hydrate($row, $workspace), $rows);
    }

    public function countByGoal(WorkspaceScope $workspace, string $goalId): int
    {
        return (int) GoalRow::text($this->connection->fetchOne('SELECT count(*) FROM budget_goal_versions WHERE workspace_id = :workspace_id AND goal_id = :goal_id', ['workspace_id' => $workspace->id, 'goal_id' => $goalId]));
    }

    public function add(GoalVersion $version): void
    {
        $this->connection->insert('budget_goal_versions', ['id' => $version->id, 'workspace_id' => $version->workspace->id, ...GoalVersionRow::columns($version)]);
    }
}
