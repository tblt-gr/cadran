<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicyRepository;
use App\Module\Budget\Domain\EmergencyFund\EssentialMode;
use App\Module\Budget\Domain\EmergencyFund\ReferenceMode;
use App\Module\Budget\Domain\EmergencyFund\TargetMode;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(EmergencyFundPolicyRepository::class)]
final readonly class DbalEmergencyFundPolicyRepository implements EmergencyFundPolicyRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace): ?EmergencyFundPolicy
    {
        $row = $this->connection->fetchAssociative(
            'SELECT workspace_id, essential_mode, essential_category_ids, reference_mode, trailing_months, target_months, version, updated_at, updated_by, target_mode, target_amount, target_asset_code, target_ratio
             FROM budget_emergency_fund_policies WHERE workspace_id = :workspace_id',
            ['workspace_id' => $workspace->id],
        );
        if (false === $row) {
            return null;
        }
        if ($workspace->id !== $row['workspace_id']) {
            throw new \UnexpectedValueException('An emergency-fund policy row escaped its requested workspace.');
        }
        $ids = json_decode(GoalRow::text($row['essential_category_ids']), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new \UnexpectedValueException('Expected an emergency-fund category list.');
        }

        return new EmergencyFundPolicy(
            $workspace,
            EssentialMode::from(GoalRow::text($row['essential_mode'])),
            array_map(GoalRow::text(...), $ids),
            ReferenceMode::from(GoalRow::text($row['reference_mode'])),
            null === $row['trailing_months'] ? null : (int) GoalRow::text($row['trailing_months']),
            DecimalValue::fromString(self::withoutTrailingZeros(GoalRow::text($row['target_months']))),
            (int) GoalRow::text($row['version']),
            new \DateTimeImmutable(GoalRow::text($row['updated_at'])),
            GoalRow::text($row['updated_by']),
            TargetMode::from(GoalRow::text($row['target_mode'])),
            null === $row['target_amount'] ? null : DecimalValue::fromString(self::withoutTrailingZeros(GoalRow::text($row['target_amount']))),
            null === $row['target_asset_code'] ? null : AssetCode::fromString(GoalRow::text($row['target_asset_code'])),
            null === $row['target_ratio'] ? null : DecimalValue::fromString(self::withoutTrailingZeros(GoalRow::text($row['target_ratio']))),
        );
    }

    public function save(EmergencyFundPolicy $policy, int $expectedVersion): bool
    {
        if (null === $policy->updatedAt || null === $policy->updatedBy) {
            throw new \UnexpectedValueException('A stored emergency-fund policy names when and by whom it was saved.');
        }
        $values = [
            'workspace_id' => $policy->workspace->id,
            'essential_mode' => $policy->essentialMode->value,
            'essential_category_ids' => json_encode($policy->essentialCategoryIds, JSON_THROW_ON_ERROR),
            'reference_mode' => $policy->referenceMode->value,
            'trailing_months' => $policy->trailingMonths,
            'target_months' => $policy->targetMonths->toString(),
            'version' => $policy->version,
            'updated_at' => $policy->updatedAt->format('Y-m-d H:i:s.uP'),
            'updated_by' => $policy->updatedBy,
            'target_mode' => $policy->targetMode->value,
            'target_amount' => $policy->targetAmount?->toString(),
            'target_asset_code' => $policy->targetAssetCode?->toString(),
            'target_ratio' => $policy->targetRatio?->toString(),
        ];
        $types = ['trailing_months' => ParameterType::INTEGER, 'version' => ParameterType::INTEGER];
        if (EmergencyFundPolicy::UNSAVED_VERSION === $expectedVersion) {
            // A concurrent first save inserts nothing instead of aborting the business transaction.
            return 1 === (int) $this->connection->executeStatement(
                'INSERT INTO budget_emergency_fund_policies (workspace_id, essential_mode, essential_category_ids, reference_mode, trailing_months, target_months, version, updated_at, updated_by, target_mode, target_amount, target_asset_code, target_ratio)
                 VALUES (:workspace_id, :essential_mode, :essential_category_ids, :reference_mode, :trailing_months, :target_months, :version, :updated_at, :updated_by, :target_mode, :target_amount, :target_asset_code, :target_ratio)
                 ON CONFLICT (workspace_id) DO NOTHING',
                $values,
                $types,
            );
        }

        return 1 === (int) $this->connection->executeStatement(
            'UPDATE budget_emergency_fund_policies SET essential_mode = :essential_mode, essential_category_ids = :essential_category_ids,
                reference_mode = :reference_mode, trailing_months = :trailing_months, target_months = :target_months,
                version = :version, updated_at = :updated_at, updated_by = :updated_by,
                target_mode = :target_mode, target_amount = :target_amount, target_asset_code = :target_asset_code, target_ratio = :target_ratio
             WHERE workspace_id = :workspace_id AND version = :expected_version',
            [...$values, 'expected_version' => $expectedVersion],
            [...$types, 'expected_version' => ParameterType::INTEGER],
        );
    }

    /** NUMERIC(50,24) pads a stored target to 24 places; the policy keeps its own, shorter form. */
    private static function withoutTrailingZeros(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
