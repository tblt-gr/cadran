<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class AccountGroupRow
{
    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row, WorkspaceScope $workspace): AccountGroup
    {
        if ($workspace->id !== self::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A group row escaped its requested workspace.');
        }

        return new AccountGroup(
            id: self::text($row['id'] ?? null),
            workspace: $workspace,
            label: self::text($row['label'] ?? null),
            parentId: self::nullableText($row['parent_id'] ?? null),
            sortOrder: (int) self::text($row['sort_order'] ?? null),
            depth: (int) self::text($row['depth'] ?? null),
            version: (int) self::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(self::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(self::text($row['updated_at'] ?? null)),
            archivedAt: self::instant($row['archived_at'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(AccountGroup $group): array
    {
        return [
            'label' => $group->label,
            'parent_id' => $group->parentId,
            'sort_order' => $group->sortOrder,
            'depth' => $group->depth,
            'version' => $group->version,
            'updated_at' => $group->updatedAt->format('Y-m-d H:i:s.uP'),
            'archived_at' => $group->archivedAt?->format('Y-m-d H:i:s.uP'),
        ];
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

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable(self::text($value));
    }
}
