<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** One account of the net worth pie: its signed value and its weight against the eligible net worth. */
final readonly class AccountOverviewCompositionAccountView
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $institution,
        public ?string $primaryGroupId,
        public ?string $color,
        public ?NetWorthAmountView $value,
        public ShareView $share,
    ) {
    }
}
