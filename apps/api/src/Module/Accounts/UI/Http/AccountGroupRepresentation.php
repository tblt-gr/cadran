<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountGroupPage;
use App\Module\Accounts\Application\AccountGroupView;
use App\Module\Accounts\Application\ShareView;

final readonly class AccountGroupRepresentation
{
    /** @return array<string, mixed> */
    public static function one(AccountGroupView $group): array
    {
        return [
            'id' => $group->id,
            'label' => $group->label,
            'parentId' => $group->parentId,
            'parentLabel' => $group->parentLabel,
            'sortOrder' => $group->sortOrder,
            'depth' => $group->depth,
            'version' => $group->version,
            'hasChildren' => $group->hasChildren,
            'canAcceptChildren' => $group->canAcceptChildren,
            'share' => self::share($group->share),
            'archivedAt' => $group->archivedAt,
        ];
    }

    /** @return array<string, mixed> */
    public static function page(AccountGroupPage $page): array
    {
        return [
            'items' => array_map(self::one(...), $page->items),
            'page' => $page->page,
            'perPage' => $page->perPage,
            'total' => $page->total,
        ];
    }

    /** @return array{ratio: ?string, percent: ?string, percentDisplay: ?string, reason: ?string} */
    public static function share(ShareView $share): array
    {
        return [
            'ratio' => $share->ratio,
            'percent' => $share->percent,
            'percentDisplay' => $share->percentDisplay,
            'reason' => $share->reason,
        ];
    }
}
