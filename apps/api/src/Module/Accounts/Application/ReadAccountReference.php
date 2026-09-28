<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class ReadAccountReference
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    public function __invoke(WorkspaceScope $workspace, string $accountId): ?AccountReferenceFact
    {
        $account = $this->accounts->find($workspace, $accountId);

        return null === $account ? null : new AccountReferenceFact($account->id, $account->label, null !== $account->archivedAt);
    }
}
