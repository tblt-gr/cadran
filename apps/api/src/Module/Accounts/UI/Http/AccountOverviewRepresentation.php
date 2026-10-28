<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountMoveView;
use App\Module\Accounts\Application\AccountOverviewGroupView;
use App\Module\Accounts\Application\AccountOverviewRow;
use App\Module\Accounts\Application\AccountOverviewRowPage;
use App\Module\Accounts\Application\AccountOverviewView;
use App\Module\Accounts\Application\NetWorthAmountView;

/** The wire shape of the unified account overview. */
final readonly class AccountOverviewRepresentation
{
    /** @return array<string, mixed> */
    public static function overview(AccountOverviewView $overview): array
    {
        return [
            'asOf' => $overview->asOf,
            'accountCount' => $overview->accountCount,
            'groups' => array_map(self::group(...), $overview->groups),
        ];
    }

    /** @return array<string, mixed> */
    public static function rows(AccountOverviewRowPage $page): array
    {
        return [
            'items' => array_map(self::row(...), $page->items),
            'nextCursor' => $page->nextCursor,
            'orderVersion' => $page->orderVersion,
        ];
    }

    /** @return array<string, mixed> */
    private static function row(AccountOverviewRow $row): array
    {
        return AccountRepresentation::one($row->account) + [
            'primaryGroupLabel' => $row->primaryGroupLabel,
            'primaryGroupParentLabel' => $row->primaryGroupParentLabel,
            'groupOrderVersion' => $row->groupOrderVersion,
        ];
    }

    /** @return array<string, mixed> */
    public static function move(AccountMoveView $move): array
    {
        return ['groupId' => $move->groupId, 'orderVersion' => $move->orderVersion, 'rank' => $move->rank];
    }

    /** @return array<string, mixed> */
    private static function group(AccountOverviewGroupView $group): array
    {
        return [
            'groupId' => $group->groupId,
            'label' => $group->label,
            'parentId' => $group->parentId,
            'depth' => $group->depth,
            'color' => $group->color,
            'resolvedColor' => $group->resolvedColor,
            'defaultColor' => $group->defaultColor,
            'memberCount' => $group->memberCount,
            'total' => self::amount($group->total),
            'totalReason' => $group->totalReason,
            'share' => AccountGroupRepresentation::share($group->share),
            'quality' => $group->quality,
            'orderVersion' => $group->orderVersion,
            'archivedAt' => $group->archivedAt,
            'parentLabel' => $group->parentLabel,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function amount(?NetWorthAmountView $amount): ?array
    {
        return null === $amount ? null : [
            'value' => $amount->amount,
            'assetCode' => $amount->asset,
            'display' => null === $amount->display ? null : [
                'value' => $amount->display,
                'assetCode' => $amount->asset,
            ],
            'belowDisplayStep' => $amount->belowDisplayStep,
        ];
    }
}
