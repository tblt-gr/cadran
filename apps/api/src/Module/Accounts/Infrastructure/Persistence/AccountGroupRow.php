<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupColorMode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;

final readonly class AccountGroupRow
{
    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row, WorkspaceScope $workspace): AccountGroup
    {
        if ($workspace->id !== DbalValue::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A group row escaped its requested workspace.');
        }

        return new AccountGroup(
            id: DbalValue::text($row['id'] ?? null),
            workspace: $workspace,
            label: DbalValue::text($row['label'] ?? null),
            parentId: DbalValue::nullableText($row['parent_id'] ?? null),
            sortOrder: (int) DbalValue::text($row['sort_order'] ?? null),
            depth: (int) DbalValue::text($row['depth'] ?? null),
            version: (int) DbalValue::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(DbalValue::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(DbalValue::text($row['updated_at'] ?? null)),
            archivedAt: DbalValue::nullableInstant($row['archived_at'] ?? null),
            color: DbalValue::nullableText($row['color'] ?? null),
            colorMode: AccountGroupColorMode::from(DbalValue::text($row['color_mode'] ?? null)),
            colorSlot: (int) DbalValue::text($row['color_slot'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(AccountGroup $group): array
    {
        return [
            'label' => $group->label,
            'color' => $group->color,
            'color_mode' => $group->colorMode->value,
            'color_slot' => $group->colorSlot,
            'parent_id' => $group->parentId,
            'sort_order' => $group->sortOrder,
            'depth' => $group->depth,
            'version' => $group->version,
            'updated_at' => $group->updatedAt->format('Y-m-d H:i:s.uP'),
            'archived_at' => $group->archivedAt?->format('Y-m-d H:i:s.uP'),
        ];
    }
}
