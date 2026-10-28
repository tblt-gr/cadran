<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

final readonly class NetWorthShares
{
    /**
     * @param array<string, NetWorthShare> $accounts
     * @param array<string, NetWorthShare> $groups
     * @param array<string, NetWorthShare> $directGroups weight of the accounts held directly by a group
     */
    public function __construct(
        public array $accounts,
        public array $groups,
        public ?NetWorthShare $ungrouped = null,
        public array $directGroups = [],
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

    /** The weight of the accounts whose primary group is exactly this group. */
    public function directGroup(string $groupId): NetWorthShare
    {
        return $this->directGroups[$groupId] ?? NetWorthShare::none(null);
    }

    /** The bucket of eligible accounts that have no primary group. */
    public function ungrouped(): NetWorthShare
    {
        return $this->ungrouped ?? NetWorthShare::none(null);
    }
}
