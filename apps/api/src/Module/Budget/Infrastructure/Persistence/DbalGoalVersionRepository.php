<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(GoalVersionRepository::class)]
final readonly class DbalGoalVersionRepository implements GoalVersionRepository
{
    private const string COLUMNS = 'id, workspace_id, goal_id, version_no, effective_from, value_type, target_amount, target_scale, ratio, ratio_scale, tolerance, tolerance_scale, schedule_mode, retroactive, reason, created_at, created_by';

    public function __construct(private Connection $connection)
    {
    }

    public function listByGoal(WorkspaceScope $workspace, string $goalId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM budget_goal_versions WHERE workspace_id = :workspace_id AND goal_id = :goal_id ORDER BY version_no DESC LIMIT :limit', ['workspace_id' => $workspace->id, 'goal_id' => $goalId, 'limit' => $limit], ['limit' => ParameterType::INTEGER]);

        $parts = $this->partsByVersion($workspace, array_map(static fn (array $row): string => GoalRow::text($row['id'] ?? null), $rows));

        return array_map(static fn (array $row): GoalVersion => GoalVersionRow::hydrate($row, $workspace, $parts[GoalRow::text($row['id'] ?? null)] ?? []), $rows);
    }

    public function listByGoals(WorkspaceScope $workspace, array $goalIds): array
    {
        if ([] === $goalIds) {
            return [];
        }
        // A goal keeps at most 50 versions, which bounds the read.
        $rows = $this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM budget_goal_versions WHERE workspace_id = :workspace_id AND goal_id IN (:goal_ids) ORDER BY goal_id, version_no DESC LIMIT :limit', ['workspace_id' => $workspace->id, 'goal_ids' => $goalIds, 'limit' => count($goalIds) * 50], ['goal_ids' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER]);
        $parts = $this->partsByVersion($workspace, array_map(static fn (array $row): string => GoalRow::text($row['id'] ?? null), $rows));
        $byGoal = [];
        foreach ($rows as $row) {
            $byGoal[GoalRow::text($row['goal_id'] ?? null)][] = GoalVersionRow::hydrate($row, $workspace, $parts[GoalRow::text($row['id'] ?? null)] ?? []);
        }

        return $byGoal;
    }

    public function deleteEffectiveAfter(WorkspaceScope $workspace, string $goalId, string $month): int
    {
        // The schedule parts go with their version through the foreign key's ON DELETE CASCADE.
        return (int) $this->connection->executeStatement('DELETE FROM budget_goal_versions WHERE workspace_id = :workspace_id AND goal_id = :goal_id AND effective_from > :month', ['workspace_id' => $workspace->id, 'goal_id' => $goalId, 'month' => $month]);
    }

    public function add(GoalVersion $version): void
    {
        $this->connection->insert('budget_goal_versions', ['id' => $version->id, 'workspace_id' => $version->workspace->id, ...GoalVersionRow::columns($version)], ['retroactive' => ParameterType::BOOLEAN]);
        foreach ($version->schedule->parts ?? [] as $index => $part) {
            $this->connection->insert('budget_goal_schedule_parts', ['goal_version_id' => $version->id, 'workspace_id' => $version->workspace->id, 'month_index' => $index + 1, 'amount' => $part->toString(), 'amount_scale' => $part->scale()]);
        }
    }

    /**
     * @param list<string> $versionIds
     *
     * @return array<string, list<array<string, mixed>>> the stored parts of each version, January first
     */
    private function partsByVersion(WorkspaceScope $workspace, array $versionIds): array
    {
        if ([] === $versionIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative('SELECT goal_version_id, month_index, amount, amount_scale FROM budget_goal_schedule_parts WHERE workspace_id = :workspace_id AND goal_version_id IN (:ids) ORDER BY goal_version_id, month_index', ['workspace_id' => $workspace->id, 'ids' => $versionIds], ['ids' => ArrayParameterType::STRING]);
        $parts = [];
        foreach ($rows as $row) {
            $parts[GoalRow::text($row['goal_version_id'] ?? null)][] = $row;
        }

        return $parts;
    }
}
