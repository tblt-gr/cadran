<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionRepository;

/** The calendar months holding a live transaction, so a month picker can disable the empty ones. */
final readonly class ReadTransactionMonths
{
    public function __construct(private TransactionRepository $transactions)
    {
    }

    /** @return list<string> `YYYY-MM`, oldest first */
    public function __invoke(WorkspaceScope $workspace): array
    {
        return $this->transactions->liveBookedMonths($workspace);
    }
}
