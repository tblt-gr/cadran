<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\BudgetScopeType;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetValueType;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class BudgetTargetRow
{
    /** @param array<string, mixed> $row */
    public static function hydrate(array $row, WorkspaceScope $workspace): BudgetTarget
    {
        if ($workspace->id !== self::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A budget target row escaped its requested workspace.');
        }

        $amountValue = self::nullableText($row['amount_value'] ?? null);
        $ratioValue = self::nullableText($row['ratio_value'] ?? null);

        return new BudgetTarget(
            id: self::text($row['id'] ?? null),
            workspace: $workspace,
            planId: self::text($row['plan_id'] ?? null),
            scopeType: BudgetScopeType::from(self::text($row['scope_type'] ?? null)),
            scopeId: self::text($row['scope_id'] ?? null),
            valueType: BudgetValueType::from(self::text($row['value_type'] ?? null)),
            amount: null === $amountValue ? null : DecimalValue::fromString(self::decimal($amountValue, $row['amount_scale'] ?? null)),
            ratio: null === $ratioValue ? null : DecimalValue::fromString(self::decimal($ratioValue, $row['ratio_scale'] ?? null)),
            version: (int) self::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(self::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(self::text($row['updated_at'] ?? null)),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(BudgetTarget $target): array
    {
        return [
            'plan_id' => $target->planId,
            'scope_type' => $target->scopeType->value,
            'scope_id' => $target->scopeId,
            'value_type' => $target->valueType->value,
            'amount_value' => $target->amount?->toString(),
            'amount_scale' => $target->amount?->scale(),
            'ratio_value' => $target->ratio?->toString(),
            'ratio_scale' => $target->ratio?->scale(),
            'version' => $target->version,
            'updated_at' => $target->updatedAt->format('Y-m-d H:i:s.uP'),
        ];
    }

    /**
     * Reconstructs the canonical decimal literal from the NUMERIC value
     * Postgres returns (always at full column precision) and the scale
     * stored alongside it, exactly as {@see TransactionRow} does for a
     * transaction amount.
     */
    public static function decimal(mixed $value, mixed $scale): string
    {
        $numeric = self::text($value);
        $requestedScale = (int) self::text($scale);
        [$integer, $fraction] = array_pad(explode('.', $numeric, 2), 2, '');

        return 0 === $requestedScale
            ? $integer
            : $integer.'.'.substr(str_pad($fraction, $requestedScale, '0'), 0, $requestedScale);
    }

    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    private static function nullableText(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }
}
