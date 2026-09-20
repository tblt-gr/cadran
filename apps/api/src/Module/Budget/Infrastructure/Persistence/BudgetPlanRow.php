<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Budget\Domain\BudgetPeriodType;
use App\Module\Budget\Domain\BudgetPlan;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class BudgetPlanRow
{
    /** @param array<string, mixed> $row */
    public static function hydrate(array $row, WorkspaceScope $workspace): BudgetPlan
    {
        if ($workspace->id !== self::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A budget plan row escaped its requested workspace.');
        }

        $periodType = BudgetPeriodType::from(self::text($row['period_type'] ?? null));

        return new BudgetPlan(
            id: self::text($row['id'] ?? null),
            workspace: $workspace,
            period: BudgetPeriod::fromDate($periodType, new \DateTimeImmutable(self::text($row['period'] ?? null))),
            assetCode: AssetCode::fromString(self::text($row['asset_code'] ?? null)),
            state: BudgetPlanState::from(self::text($row['state'] ?? null)),
            version: (int) self::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(self::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(self::text($row['updated_at'] ?? null)),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(BudgetPlan $plan): array
    {
        return [
            'period_type' => $plan->period->type->value,
            'period' => $plan->period->firstDay()->format('Y-m-d'),
            'asset_code' => $plan->assetCode->toString(),
            'state' => $plan->state->value,
            'version' => $plan->version,
            'updated_at' => $plan->updatedAt->format('Y-m-d H:i:s.uP'),
        ];
    }

    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }
}
