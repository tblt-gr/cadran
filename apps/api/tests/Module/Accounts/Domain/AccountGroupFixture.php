<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Foundation\Domain\WorkspaceScope;

final class AccountGroupFixture
{
    public const string ID = '00000000-0000-7000-8000-0000000000b1';
    public const string CHILD_ID = '00000000-0000-7000-8000-0000000000b2';
    public const string OTHER_ID = '00000000-0000-7000-8000-0000000000b3';
    public const string GRANDCHILD_ID = '00000000-0000-7000-8000-0000000000b4';
    public const string BRANCH_ID = '00000000-0000-7000-8000-0000000000b5';
    public const string DEEP_ID = '00000000-0000-7000-8000-0000000000b6';
    public const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    public const string OTHER_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';

    public static function group(
        string $id = self::ID,
        string $workspace = self::WORKSPACE,
        string $label = 'Épargne',
        ?string $parentId = null,
        int $depth = 1,
        ?\DateTimeImmutable $archivedAt = null,
    ): AccountGroup {
        $now = new \DateTimeImmutable('2026-09-05T10:00:00+00:00');

        return new AccountGroup(
            id: $id,
            workspace: WorkspaceScope::fromString($workspace),
            label: $label,
            parentId: $parentId,
            sortOrder: 0,
            depth: $depth,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            archivedAt: $archivedAt,
        );
    }
}
