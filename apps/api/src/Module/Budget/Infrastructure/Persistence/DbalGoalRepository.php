<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalFilter;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(GoalRepository::class)]
final readonly class DbalGoalRepository implements GoalRepository
{
    private const string COLUMNS = 'id, workspace_id, label, periodicity, metric, scope_type, scope_ref, asset_code, start_period, end_period, created_at, created_by, version, direction';

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

    public function search(WorkspaceScope $workspace, GoalFilter $filter, int $limit, int $offset): array
    {
        [$extra, $parameters, $types] = self::criteria($workspace, $filter);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM budget_goals WHERE workspace_id = :workspace_id'.$extra.' ORDER BY created_at, id LIMIT :limit OFFSET :offset',
            [...$parameters, 'limit' => $limit, 'offset' => $offset],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): Goal => GoalRow::hydrate($row, $workspace), $rows);
    }

    public function count(WorkspaceScope $workspace, GoalFilter $filter): int
    {
        [$extra, $parameters, $types] = self::criteria($workspace, $filter);

        return (int) GoalRow::text($this->connection->fetchOne('SELECT count(*) FROM budget_goals WHERE workspace_id = :workspace_id'.$extra, $parameters, $types));
    }

    public function countCurrent(WorkspaceScope $workspace, CalendarMonth $today): int
    {
        return $this->count($workspace, new GoalFilter($today));
    }

    /**
     * Every condition after the workspace predicate. The first and last covered month are derived in SQL so that a yearly and a monthly goal
     * compare on the same `YYYY-MM` scale as the requested period.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType>}
     */
    private static function criteria(WorkspaceScope $workspace, GoalFilter $filter): array
    {
        $where = [];
        $parameters = ['workspace_id' => $workspace->id];
        $types = [];
        if (null !== $filter->periodicity) {
            $where[] = 'periodicity = :periodicity';
            $parameters['periodicity'] = $filter->periodicity->value;
        }
        if ($filter->currentOnly) {
            $where[] = "(end_period IS NULL OR end_period >= CASE periodicity WHEN 'YEARLY' THEN :today_year ELSE :today_month END)";
            $parameters['today_year'] = (string) $filter->today->year;
            $parameters['today_month'] = $filter->today->key();
        }
        if (null !== $filter->period) {
            $year = 4 === strlen($filter->period);
            $where[] = "(CASE periodicity WHEN 'YEARLY' THEN start_period || '-01' ELSE start_period END) <= :period_last AND (end_period IS NULL OR (CASE periodicity WHEN 'YEARLY' THEN end_period || '-12' ELSE end_period END) >= :period_first)";
            $parameters['period_first'] = $year ? $filter->period.'-01' : $filter->period;
            $parameters['period_last'] = $year ? $filter->period.'-12' : $filter->period;
        }

        return [[] === $where ? '' : ' AND '.implode(' AND ', $where), $parameters, $types];
    }

    public function covering(WorkspaceScope $workspace, string $firstMonth, string $lastMonth, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS." FROM budget_goals WHERE workspace_id = :workspace_id AND (CASE periodicity WHEN 'YEARLY' THEN start_period || '-01' ELSE start_period END) <= :last AND (end_period IS NULL OR (CASE periodicity WHEN 'YEARLY' THEN end_period || '-12' ELSE end_period END) >= :first) ORDER BY created_at, id LIMIT :limit",
            ['workspace_id' => $workspace->id, 'first' => $firstMonth, 'last' => $lastMonth, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): Goal => GoalRow::hydrate($row, $workspace), $rows);
    }

    public function delete(Goal $goal, int $expectedVersion): bool
    {
        // The versions and their schedule parts go with the goal through the foreign keys' ON DELETE CASCADE.
        return 1 === (int) $this->connection->delete('budget_goals', ['workspace_id' => $goal->workspace->id, 'id' => $goal->id, 'version' => $expectedVersion]);
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
