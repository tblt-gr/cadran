<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupColor;
use App\Module\Accounts\Domain\NetWorthShare;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reference\Domain\Asset;

/**
 * The exclusive weight of one group: what its own accounts and its
 * descendants are worth, and the share of net worth that represents.
 *
 * An account has one exclusive membership, so two groups of one depth never
 * claim the same account. They still do not add up to the published total: an account
 * with no primary group belongs to no bucket at all.
 */
final readonly class NetWorthAllocationView
{
    public function __construct(
        public string $groupId,
        public string $label,
        public ?string $parentId,
        public int $depth,
        public ?NetWorthAmountView $value,
        public ShareView $share,
        public string $color,
        public ?NetWorthAmountView $previousValue = null,
    ) {
    }

    public static function of(
        AccountGroup $group,
        ?DecimalValue $value,
        ?AssetCode $asset,
        NetWorthShare $share,
        ?Asset $reference,
        ?DecimalValue $previousValue = null,
        ?AssetCode $previousAsset = null,
        ?Asset $previousReference = null,
    ): self {
        return new self(
            groupId: $group->id,
            label: $group->label,
            parentId: $group->parentId,
            depth: $group->depth,
            value: NetWorthAmountView::of($value, $asset, $reference),
            share: ShareView::fromShare($share),
            color: AccountGroupColor::resolve($group),
            previousValue: NetWorthAmountView::of($previousValue, $previousAsset, $previousReference),
        );
    }
}
