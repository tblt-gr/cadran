<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * One slice of a group: a sub-group, the group's own accounts or one account.
 * The institution and primary group are set for an account slice only.
 */
final readonly class GroupCompositionEntryView
{
    public function __construct(
        public ?string $kind,
        public string $id,
        public string $label,
        public string $color,
        public ?NetWorthAmountView $value,
        public ShareView $share,
        public ?string $institution = null,
        public ?string $primaryGroupId = null,
    ) {
    }
}
