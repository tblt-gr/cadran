<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

final readonly class NetWorthShares
{
    /**
     * @param array<string, NetWorthShare> $accounts
     * @param array<string, NetWorthShare> $groups
     */
    public function __construct(
        public array $accounts,
        public array $groups,
    ) {
    }

    public function account(string $accountId): NetWorthShare
    {
        return $this->accounts[$accountId] ?? NetWorthShare::none(null);
    }

    public function group(string $groupId): NetWorthShare
    {
        return $this->groups[$groupId] ?? NetWorthShare::none(null);
    }
}
