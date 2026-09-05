<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\Category;

/**
 * Structural facts a reviewer needs about a lifecycle change. The label, the
 * icon and the colour stay out: they are free text the holder chose, and an
 * audit trail must stay readable by someone who may not see the data itself.
 */
final class CategoryAuditFingerprint
{
    /** @return array<string, bool|int|string|null> */
    public static function of(Category $category): array
    {
        return [
            'type' => $category->type->value,
            'hasParent' => null !== $category->parentId,
            'depth' => $category->depth,
            'archived' => null !== $category->archivedAt,
            'used' => null !== $category->usedAt,
            'version' => $category->version,
        ];
    }
}
