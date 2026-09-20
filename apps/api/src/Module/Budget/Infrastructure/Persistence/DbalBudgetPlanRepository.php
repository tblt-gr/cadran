<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Application\BudgetPlanConflict;
use App\Module\Budget\Domain\BudgetPeriodType;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(BudgetPlanRepository::class)]
final readonly class DbalBudgetPlanRepository implements BudgetPlanRepository
{
    private const string COLUMNS = 'id, workspace_id, period_type, period, asset_code, state, version, created_at, updated_at';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?BudgetPlan
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM budget_plans WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : BudgetPlanRow::hydrate($row, $workspace);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?BudgetPlan
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM budget_plans WHERE workspace_id = :workspace_id AND id = :id FOR UPDATE',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : BudgetPlanRow::hydrate($row, $workspace);
    }

    public function findByPeriod(WorkspaceScope $workspace, BudgetPeriodType $periodType, \DateTimeImmutable $period): ?BudgetPlan
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM budget_plans WHERE workspace_id = :workspace_id AND period_type = :period_type AND period = :period',
            ['workspace_id' => $workspace->id, 'period_type' => $periodType->value, 'period' => $period->format('Y-m-d')],
        );

        return false === $row ? null : BudgetPlanRow::hydrate($row, $workspace);
    }

    public function findActiveByPeriod(WorkspaceScope $workspace, BudgetPeriodType $periodType, \DateTimeImmutable $period): ?BudgetPlan
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM budget_plans WHERE workspace_id = :workspace_id AND period_type = :period_type AND period = :period AND state = :state',
            ['workspace_id' => $workspace->id, 'period_type' => $periodType->value, 'period' => $period->format('Y-m-d'), 'state' => 'ACTIVE'],
        );

        return false === $row ? null : BudgetPlanRow::hydrate($row, $workspace);
    }

    public function list(WorkspaceScope $workspace, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM budget_plans WHERE workspace_id = :workspace_id ORDER BY period DESC, id DESC LIMIT :limit OFFSET :offset',
            ['workspace_id' => $workspace->id, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): BudgetPlan => BudgetPlanRow::hydrate($row, $workspace), $rows);
    }

    public function count(WorkspaceScope $workspace): int
    {
        return (int) BudgetPlanRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM budget_plans WHERE workspace_id = :workspace_id',
            ['workspace_id' => $workspace->id],
        ));
    }

    public function add(BudgetPlan $plan): void
    {
        try {
            $this->connection->insert('budget_plans', [
                'id' => $plan->id,
                'workspace_id' => $plan->workspace->id,
                ...BudgetPlanRow::columns($plan),
                'created_at' => $plan->createdAt->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new BudgetPlanConflict('A budget plan already exists for this workspace and period.', previous: $exception);
        }
    }

    public function update(BudgetPlan $plan, int $expectedVersion): bool
    {
        try {
            return 1 === (int) $this->connection->update(
                'budget_plans',
                BudgetPlanRow::columns($plan),
                ['workspace_id' => $plan->workspace->id, 'id' => $plan->id, 'version' => $expectedVersion],
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new BudgetPlanConflict('Another plan is already active for this workspace and period.', previous: $exception);
        }
    }
}
