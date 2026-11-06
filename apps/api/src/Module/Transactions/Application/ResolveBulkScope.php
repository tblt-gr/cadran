<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;

/** Turns the scope of a bulk operation into the sorted identifiers it targets. */
final readonly class ResolveBulkScope
{
    public function __construct(
        private TransactionRepository $transactions,
        private ResolveTransactionFilters $resolveFilters,
    ) {
    }

    /**
     * Sorted and deduplicated: it is the lock order, and a repeated identifier
     * must not be counted twice.
     *
     * @return list<string>
     *
     * @throws BulkScopeTooLarge
     * @throws BulkScopeChanged
     */
    public function ids(WorkspaceScope $workspace, BulkEditInput $input): array
    {
        if (null !== $input->ids) {
            $ids = array_values(array_unique($input->ids));
            if (count($ids) > BulkEditTransactions::MAX_TRANSACTIONS) {
                throw new BulkScopeTooLarge();
            }
            sort($ids);

            return $ids;
        }

        $ids = $this->matchingIds($workspace, $input);
        if (count($ids) > BulkEditTransactions::MAX_TRANSACTIONS) {
            throw new BulkScopeTooLarge();
        }
        if (count($ids) !== $input->expectedCount) {
            throw new BulkScopeChanged(count($ids));
        }

        return $ids;
    }

    /**
     * At most one more than the cap, so an oversized scope is detected without reading it all.
     *
     * @return list<string> sorted identifiers
     */
    public function matchingIds(WorkspaceScope $workspace, BulkEditInput $input): array
    {
        $query = $input->filters ?? throw new \LogicException('A bulk scope names identifiers or filters.');
        $filters = ($this->resolveFilters)($workspace, $query);
        $found = null === $filters ? [] : $this->transactions->search($workspace, $filters, BulkEditTransactions::MAX_TRANSACTIONS + 1, null);
        $ids = array_map(static fn (Transaction $transaction): string => $transaction->id, $found);
        sort($ids);

        return $ids;
    }
}
