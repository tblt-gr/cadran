<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionRepository;

/** The calendar month of the workspace's oldest live transaction, so a picker never offers years with nothing to show. */
final readonly class ReadFirstTransactionMonth
{
    public function __construct(private TransactionRepository $transactions)
    {
    }

    /** @return string|null `YYYY-MM`, or null when the workspace has no live transaction */
    public function __invoke(WorkspaceScope $workspace): ?string
    {
        return $this->transactions->firstLiveBookedOn($workspace)?->format('Y-m');
    }
}
