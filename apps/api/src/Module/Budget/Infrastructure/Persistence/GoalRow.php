<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalKind;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalState;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

final class GoalRow
{
    /** @param array<string, mixed> $row */
    public static function hydrate(array $row, WorkspaceScope $workspace): Goal
    {
        if ($workspace->id !== self::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A goal row escaped its requested workspace.');
        }

        return new Goal(
            self::text($row['id'] ?? null), $workspace, self::text($row['label'] ?? null),
            GoalKind::from(self::text($row['kind'] ?? null)), GoalMetric::from(self::text($row['metric'] ?? null)),
            GoalScopeType::from(self::text($row['scope_type'] ?? null)), self::nullable($row['scope_ref'] ?? null),
            AssetCode::fromString(self::text($row['asset_code'] ?? null)), GoalState::from(self::text($row['state'] ?? null)),
            (int) self::text($row['version'] ?? null), new \DateTimeImmutable(self::text($row['created_at'] ?? null)),
            self::text($row['created_by'] ?? null), null === ($row['closed_at'] ?? null) ? null : new \DateTimeImmutable(self::text($row['closed_at'])),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(Goal $goal): array
    {
        return ['label' => $goal->label, 'kind' => $goal->kind->value, 'metric' => $goal->metric->value, 'scope_type' => $goal->scopeType->value, 'scope_ref' => $goal->scopeRef, 'asset_code' => $goal->assetCode->toString(), 'state' => $goal->state->value, 'closed_at' => $goal->closedAt?->format('Y-m-d H:i:s.uP'), 'version' => $goal->version];
    }

    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    private static function nullable(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }
}
