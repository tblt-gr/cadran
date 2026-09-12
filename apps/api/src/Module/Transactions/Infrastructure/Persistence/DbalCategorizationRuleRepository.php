<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use App\Module\Transactions\Domain\Categorization\RuleConditions;
use App\Module\Transactions\Domain\Categorization\RuleDeactivationReason;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CategorizationRuleRepository::class)]
final readonly class DbalCategorizationRuleRepository implements CategorizationRuleRepository
{
    private const string COLUMNS = 'id, workspace_id, label, priority, account_scope, conditions, target_category_id, target_axes, target_counterparty, effective_from, effective_to, active, deactivated_reason, applied_count, version, created_at, updated_at, archived_at';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?CategorizationRule
    {
        return $this->one($workspace, $id, false);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?CategorizationRule
    {
        return $this->one($workspace, $id, true);
    }

    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM transaction_categorization_rules WHERE workspace_id = :workspace_id'
            .($includeArchived ? '' : ' AND archived_at IS NULL').' ORDER BY priority, created_at, id LIMIT :limit OFFSET :offset',
            ['workspace_id' => $workspace->id, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(fn (array $row): CategorizationRule => $this->hydrate($workspace, $row), $rows);
    }

    public function count(WorkspaceScope $workspace, bool $includeArchived): int
    {
        return (int) TransactionRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM transaction_categorization_rules WHERE workspace_id = :workspace_id'.($includeArchived ? '' : ' AND archived_at IS NULL'),
            ['workspace_id' => $workspace->id],
        ));
    }

    public function activeInOrder(WorkspaceScope $workspace): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM transaction_categorization_rules WHERE workspace_id = :workspace_id AND active = TRUE AND archived_at IS NULL ORDER BY priority, created_at, id',
            ['workspace_id' => $workspace->id],
        );

        return array_map(fn (array $row): CategorizationRule => $this->hydrate($workspace, $row), $rows);
    }

    public function activeTargetingForUpdate(WorkspaceScope $workspace, string $categoryId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM transaction_categorization_rules WHERE workspace_id = :workspace_id AND target_category_id = :category_id AND active = TRUE AND archived_at IS NULL ORDER BY id FOR UPDATE',
            ['workspace_id' => $workspace->id, 'category_id' => $categoryId],
        );

        return array_map(fn (array $row): CategorizationRule => $this->hydrate($workspace, $row), $rows);
    }

    public function add(CategorizationRule $rule): void
    {
        $this->connection->insert('transaction_categorization_rules', [
            'id' => $rule->id,
            'workspace_id' => $rule->workspace->id,
            ...$this->columns($rule, false),
        ], ['active' => ParameterType::BOOLEAN]);
    }

    public function update(CategorizationRule $rule, int $expectedVersion): bool
    {
        return 1 === $this->connection->update(
            'transaction_categorization_rules', $this->columns($rule, false),
            ['workspace_id' => $rule->workspace->id, 'id' => $rule->id, 'version' => $expectedVersion],
            ['active' => ParameterType::BOOLEAN],
        );
    }

    public function incrementAppliedCount(WorkspaceScope $workspace, string $id, int $by): void
    {
        $this->connection->executeStatement(
            'UPDATE transaction_categorization_rules SET applied_count = applied_count + :amount WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id, 'amount' => $by],
            ['amount' => ParameterType::INTEGER],
        );
    }

    private function one(WorkspaceScope $workspace, string $id, bool $lock): ?CategorizationRule
    {
        $sql = 'SELECT '.self::COLUMNS.' FROM transaction_categorization_rules WHERE workspace_id = :workspace_id AND id = :id';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->connection->fetchAssociative(
            $sql,
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : $this->hydrate($workspace, $row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(WorkspaceScope $workspace, array $row): CategorizationRule
    {
        if ($workspace->id !== TransactionRow::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A categorization rule escaped its requested workspace.');
        }
        $scope = json_decode(TransactionRow::text($row['account_scope'] ?? null), true, flags: JSON_THROW_ON_ERROR);
        $conditions = json_decode(TransactionRow::text($row['conditions'] ?? null), true, flags: JSON_THROW_ON_ERROR);
        $axes = json_decode(TransactionRow::text($row['target_axes'] ?? null), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($scope) || !is_array($conditions) || !is_array($axes)) {
            throw new \UnexpectedValueException('A categorization rule JSON document is malformed.');
        }
        $reason = null === ($row['deactivated_reason'] ?? null) ? null : RuleDeactivationReason::from(TransactionRow::text($row['deactivated_reason']));

        return new CategorizationRule(
            TransactionRow::text($row['id'] ?? null), $workspace, TransactionRow::text($row['label'] ?? null),
            (int) TransactionRow::text($row['priority'] ?? null), array_map(TransactionRow::text(...), array_values($scope)),
            RuleConditions::fromDocument($conditions), TransactionRow::text($row['target_category_id'] ?? null),
            array_map(static fn (mixed $axis): AnalyticAxis => AnalyticAxis::from(TransactionRow::text($axis)), array_values($axes)),
            null === ($row['target_counterparty'] ?? null) ? null : TransactionRow::text($row['target_counterparty']),
            new \DateTimeImmutable(TransactionRow::text($row['effective_from'] ?? null)),
            null === ($row['effective_to'] ?? null) ? null : new \DateTimeImmutable(TransactionRow::text($row['effective_to'])),
            (bool) $row['active'], $reason, (int) TransactionRow::text($row['applied_count'] ?? null),
            (int) TransactionRow::text($row['version'] ?? null), new \DateTimeImmutable(TransactionRow::text($row['created_at'] ?? null)),
            new \DateTimeImmutable(TransactionRow::text($row['updated_at'] ?? null)),
            null === ($row['archived_at'] ?? null) ? null : new \DateTimeImmutable(TransactionRow::text($row['archived_at'])),
        );
    }

    /** @return array<string, mixed> */
    private function columns(CategorizationRule $rule, bool $includeIdentity = true): array
    {
        $columns = [
            'label' => $rule->label, 'priority' => $rule->priority,
            'account_scope' => json_encode($rule->accountScope, JSON_THROW_ON_ERROR),
            'conditions' => json_encode($rule->conditions->toDocument(), JSON_THROW_ON_ERROR),
            'target_category_id' => $rule->targetCategoryId,
            'target_axes' => json_encode(array_map(static fn (AnalyticAxis $axis): string => $axis->value, $rule->targetAxes), JSON_THROW_ON_ERROR),
            'target_counterparty' => $rule->targetCounterparty, 'effective_from' => $rule->effectiveFrom->format('Y-m-d'),
            'effective_to' => $rule->effectiveTo?->format('Y-m-d'), 'active' => $rule->active,
            'deactivated_reason' => $rule->deactivatedReason?->value, 'applied_count' => $rule->appliedCount,
            'version' => $rule->version, 'created_at' => $rule->createdAt->format('Y-m-d H:i:s.uP'),
            'updated_at' => $rule->updatedAt->format('Y-m-d H:i:s.uP'), 'archived_at' => $rule->archivedAt?->format('Y-m-d H:i:s.uP'),
        ];

        return $includeIdentity ? ['id' => $rule->id, 'workspace_id' => $rule->workspace->id, ...$columns] : $columns;
    }
}
