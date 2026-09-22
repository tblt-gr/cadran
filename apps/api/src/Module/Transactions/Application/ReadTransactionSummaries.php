<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionRepository;

/** A bounded, workspace-scoped read port for explanation surfaces. */
final readonly class ReadTransactionSummaries
{
    public const int MAX_TRANSACTIONS = 500;

    public function __construct(private TransactionRepository $transactions)
    {
    }

    /**
     * Missing identifiers are intentionally omitted so a stale or foreign
     * reference cannot reveal whether a transaction exists elsewhere.
     *
     * @param list<string> $ids
     *
     * @return list<TransactionSummaryView>
     */
    public function __invoke(WorkspaceScope $workspace, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (count($ids) > self::MAX_TRANSACTIONS) {
            throw new \InvalidArgumentException('A transaction summary read accepts at most 500 identifiers.');
        }

        return array_map(
            TransactionSummaryView::fromTransaction(...),
            $this->transactions->findMany($workspace, $ids),
        );
    }
}
