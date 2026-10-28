<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupColors;

/**
 * The heading of one table of the overview: a primary group, or the
 * ungrouped section when `groupId` is null.
 *
 * In parent mode the total is the roll-up of the whole subtree, in sub-group
 * mode only the direct accounts: the server decides, the interface never sums.
 */
final readonly class AccountOverviewGroupView
{
    public function __construct(
        public ?string $groupId,
        public ?string $label,
        public ?string $parentId,
        public int $depth,
        public ?string $color,
        public ?string $resolvedColor,
        public ?string $defaultColor,
        public int $memberCount,
        public ?NetWorthAmountView $total,
        public ?string $totalReason,
        public ShareView $share,
        public string $quality,
        public int $orderVersion,
        public ?string $archivedAt,
        public ?string $parentLabel = null,
    ) {
    }

    public static function ofGroup(
        AccountGroup $group,
        AccountGroupColors $colors,
        int $memberCount,
        ?NetWorthAmountView $total,
        ?string $totalReason,
        ShareView $share,
        string $quality,
        int $orderVersion,
        ?string $parentLabel = null,
    ): self {
        return new self(
            groupId: $group->id,
            label: $group->label,
            parentId: $group->parentId,
            depth: $group->depth,
            color: $group->color,
            resolvedColor: $colors->effective($group),
            defaultColor: $colors->defaultFor($group),
            memberCount: $memberCount,
            total: $total,
            totalReason: $totalReason,
            share: $share,
            quality: $quality,
            orderVersion: $orderVersion,
            archivedAt: $group->archivedAt?->format(DATE_ATOM),
            parentLabel: $parentLabel,
        );
    }
}
