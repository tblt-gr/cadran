<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalDirection;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;

final class GoalRow
{
    /** @param array<string, mixed> $row */
    public static function hydrate(array $row, WorkspaceScope $workspace): Goal
    {
        if ($workspace->id !== DbalValue::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A goal row escaped its requested workspace.');
        }

        return new Goal(
            DbalValue::text($row['id'] ?? null), $workspace, DbalValue::text($row['label'] ?? null),
            GoalPeriodicity::from(DbalValue::text($row['periodicity'] ?? null)), GoalMetric::from(DbalValue::text($row['metric'] ?? null)),
            GoalScopeType::from(DbalValue::text($row['scope_type'] ?? null)), DbalValue::nullableText($row['scope_ref'] ?? null),
            AssetCode::fromString(DbalValue::text($row['asset_code'] ?? null)), DbalValue::text($row['start_period'] ?? null), DbalValue::nullableText($row['end_period'] ?? null),
            (int) DbalValue::text($row['version'] ?? null), new \DateTimeImmutable(DbalValue::text($row['created_at'] ?? null)),
            DbalValue::text($row['created_by'] ?? null),
            null === ($row['direction'] ?? null) ? null : GoalDirection::from(DbalValue::text($row['direction'])),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(Goal $goal): array
    {
        return ['direction' => $goal->stockDirection?->value, 'label' => $goal->label, 'periodicity' => $goal->periodicity->value, 'metric' => $goal->metric->value, 'scope_type' => $goal->scopeType->value, 'scope_ref' => $goal->scopeRef, 'asset_code' => $goal->assetCode->toString(), 'start_period' => $goal->startPeriod, 'end_period' => $goal->endPeriod, 'version' => $goal->version];
    }
}
