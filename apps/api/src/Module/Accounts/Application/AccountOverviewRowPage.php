<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class AccountOverviewRowPage
{
    /** @param list<AccountView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public int $orderVersion,
    ) {
    }
}
