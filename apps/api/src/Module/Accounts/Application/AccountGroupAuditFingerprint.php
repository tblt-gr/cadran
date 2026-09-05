<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;

/**
 * Structural facts a reviewer needs about a group change. The label stays
 * out: it is free text the holder chose.
 */
final class AccountGroupAuditFingerprint
{
    /** @return array<string, bool|int|string|null> */
    public static function of(AccountGroup $group, bool $parentChanged = false): array
    {
        return [
            'hasParent' => null !== $group->parentId,
            'depth' => $group->depth,
            'orderChanged' => false,
            'parentChanged' => $parentChanged,
            'version' => $group->version,
        ];
    }
}
