<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class GroupCompositionView
{
    /**
     * @param list<GroupCompositionEntryView> $bySubgroup
     * @param list<GroupCompositionEntryView> $byAccount
     */
    public function __construct(
        public string $groupId,
        public string $label,
        public string $asOf,
        public ?NetWorthAmountView $total,
        public ?string $totalReason,
        public ?string $chartReason,
        public array $bySubgroup,
        public array $byAccount,
    ) {
    }
}
