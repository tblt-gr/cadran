<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Transactions\Domain\TransactionRepository;

/** The exact size of a filtered listing, shown before a filter-wide bulk action. */
final readonly class CountTransactions
{
    public function __construct(
        private CallerWorkspace $caller,
        private TransactionRepository $transactions,
        private ResolveTransactionFilters $resolveFilters,
    ) {
    }

    public function __invoke(ListTransactionsQuery $query): int
    {
        $workspace = $this->caller->resolve();
        $filters = ($this->resolveFilters)($workspace, $query);

        return null === $filters ? 0 : $this->transactions->countMatching($workspace, $filters);
    }
}
