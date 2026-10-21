<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class AccountOverviewView
{
    /**
     * @param int                            $accountCount every account of the workspace whatever the filter, so an empty filter is told apart from no account
     * @param list<AccountOverviewGroupView> $groups
     */
    public function __construct(
        public string $asOf,
        public int $accountCount,
        public array $groups,
    ) {
    }
}
