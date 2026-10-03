<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Public cross-module account catalogue; the 51st row only indicates truncation. */
final readonly class ListPortfolioAccounts
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    /** @return list<AccountView> */
    public function __invoke(WorkspaceScope $workspace): array
    {
        return array_map(AccountView::fromAccount(...), $this->accounts->listPortfolios($workspace, 51));
    }
}
