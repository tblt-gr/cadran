<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\NetWorthShare;
use App\Module\Accounts\Domain\NetWorthShareReason;

final readonly class AccountGroupView
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $parentId,
        public ?string $parentLabel,
        public int $sortOrder,
        public int $depth,
        public int $version,
        public bool $hasChildren,
        public bool $canAcceptChildren,
        public ShareView $share,
        public ?string $archivedAt,
    ) {
    }

    public static function fromGroup(
        AccountGroup $group,
        bool $hasChildren,
        ?string $parentLabel,
        ?NetWorthShare $share = null,
    ): self {
        return new self(
            id: $group->id,
            label: $group->label,
            parentId: $group->parentId,
            parentLabel: $parentLabel,
            sortOrder: $group->sortOrder,
            depth: $group->depth,
            version: $group->version,
            hasChildren: $hasChildren,
            canAcceptChildren: null === $group->archivedAt && $group->depth < AccountGroup::MAX_TREE_DEPTH,
            share: ShareView::fromShare($share ?? NetWorthShare::none(
                null === $group->archivedAt ? NetWorthShareReason::MISSING_VALUATION : null,
            )),
            archivedAt: $group->archivedAt?->format(DATE_ATOM),
        );
    }
}
