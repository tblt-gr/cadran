<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class AccountPage
{
    /** @param list<AccountView> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
