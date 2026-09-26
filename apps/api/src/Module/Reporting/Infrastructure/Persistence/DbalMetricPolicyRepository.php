<?php

declare(strict_types=1);

namespace App\Module\Reporting\Infrastructure\Persistence;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\InvalidMetricPolicy;
use App\Module\Reporting\Domain\MetricPolicy;
use App\Module\Reporting\Domain\MetricPolicyActivation;
use App\Module\Reporting\Domain\MetricPolicyRepository;
use App\Module\Reporting\Domain\NetSavingsRateFormula;
use App\Module\Reporting\Domain\SavingsRateFormula;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MetricPolicyRepository::class)]
final readonly class DbalMetricPolicyRepository implements MetricPolicyRepository
{
    private const string POLICY_COLUMNS = 'version, label, cash_excluded_account_kinds, savings_rate_formula, net_savings_rate_formula, created_at, created_by';

    public function __construct(private Connection $connection)
    {
    }

    public function lock(WorkspaceScope $workspace): void
    {
        $this->connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => 'reporting_metric_policy:'.$workspace->id],
        );
    }

    public function find(WorkspaceScope $workspace, int $version): ?MetricPolicy
    {
        if (MetricPolicy::SYSTEM_VERSION === $version) {
            return MetricPolicy::systemV1();
        }
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::POLICY_COLUMNS.' FROM reporting_metric_policies WHERE workspace_id = :workspace_id AND version = :version',
            ['workspace_id' => $workspace->id, 'version' => $version],
        );

        if (false === $row) {
            return null;
        }

        try {
            return self::hydrate($row);
        } catch (\ValueError|\UnexpectedValueException|\JsonException|InvalidMetricPolicy) {
            // A stored definition that no longer decodes is reported as unknown, never replaced by version 1.
            return null;
        }
    }

    public function listStored(WorkspaceScope $workspace): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::POLICY_COLUMNS.' FROM reporting_metric_policies WHERE workspace_id = :workspace_id ORDER BY version ASC LIMIT '.self::MAX_VERSIONS,
            ['workspace_id' => $workspace->id],
        );

        $policies = [];
        foreach ($rows as $row) {
            try {
                $policies[] = self::hydrate($row);
            } catch (\ValueError|\UnexpectedValueException|\JsonException|InvalidMetricPolicy) {
                continue;
            }
        }

        return $policies;
    }

    public function countStored(WorkspaceScope $workspace): int
    {
        return (int) self::scalar($this->connection->fetchOne(
            'SELECT COUNT(*) FROM reporting_metric_policies WHERE workspace_id = :workspace_id',
            ['workspace_id' => $workspace->id],
        ));
    }

    public function latestVersion(WorkspaceScope $workspace): int
    {
        return max(MetricPolicy::SYSTEM_VERSION, (int) self::scalar($this->connection->fetchOne(
            'SELECT COALESCE(MAX(version), 1) FROM reporting_metric_policies WHERE workspace_id = :workspace_id',
            ['workspace_id' => $workspace->id],
        )));
    }

    public function add(WorkspaceScope $workspace, string $id, MetricPolicy $policy): void
    {
        $this->connection->insert('reporting_metric_policies', [
            'id' => $id,
            'workspace_id' => $workspace->id,
            'version' => $policy->version,
            'label' => $policy->label,
            'cash_excluded_account_kinds' => json_encode(array_map(static fn (AccountKind $kind): string => $kind->value, $policy->cashExcludedAccountKinds), JSON_THROW_ON_ERROR),
            'savings_rate_formula' => $policy->savingsRateFormula->value,
            'net_savings_rate_formula' => $policy->netSavingsRateFormula->value,
            'created_at' => $policy->createdAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            'created_by' => $policy->createdBy,
        ]);
    }

    public function activations(WorkspaceScope $workspace): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT policy_version, active_from, reason, created_by FROM reporting_metric_policy_activations WHERE workspace_id = :workspace_id ORDER BY active_from ASC LIMIT '.self::MAX_ACTIVATIONS,
            ['workspace_id' => $workspace->id],
        );

        return array_map(static fn (array $row): MetricPolicyActivation => new MetricPolicyActivation(
            (int) self::scalar($row['policy_version'] ?? null),
            self::instant($row['active_from'] ?? null),
            null === ($row['reason'] ?? null) ? null : self::scalar($row['reason']),
            self::scalar($row['created_by'] ?? null),
        ), $rows);
    }

    public function addActivation(WorkspaceScope $workspace, string $id, MetricPolicyActivation $activation): void
    {
        $this->connection->insert('reporting_metric_policy_activations', [
            'id' => $id,
            'workspace_id' => $workspace->id,
            'policy_version' => $activation->policyVersion,
            'active_from' => $activation->activeFrom->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            'reason' => $activation->reason,
            'created_by' => $activation->createdBy,
        ]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): MetricPolicy
    {
        $kinds = json_decode(self::scalar($row['cash_excluded_account_kinds'] ?? null), true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($kinds)) {
            throw new \UnexpectedValueException('A stored metric policy holds a list of account kinds.');
        }

        return MetricPolicy::rehydrate(
            (int) self::scalar($row['version'] ?? null),
            self::scalar($row['label'] ?? null),
            array_map(static fn (mixed $kind): AccountKind => AccountKind::from(self::scalar($kind)), array_values($kinds)),
            SavingsRateFormula::from(self::scalar($row['savings_rate_formula'] ?? null)),
            NetSavingsRateFormula::from(self::scalar($row['net_savings_rate_formula'] ?? null)),
            self::instant($row['created_at'] ?? null),
            self::scalar($row['created_by'] ?? null),
        );
    }

    private static function instant(mixed $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::scalar($value)))->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function scalar(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \UnexpectedValueException('A stored metric policy column holds a scalar.');
        }

        return (string) $value;
    }
}
