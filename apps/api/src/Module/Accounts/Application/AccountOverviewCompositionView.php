<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class AccountOverviewCompositionView
{
    /** @param list<AccountOverviewCompositionAccountView> $accounts */
    public function __construct(
        public string $asOf,
        public ?NetWorthAmountView $total,
        public ?string $totalReason,
        public ?string $chartReason,
        public array $accounts,
    ) {
    }
}
