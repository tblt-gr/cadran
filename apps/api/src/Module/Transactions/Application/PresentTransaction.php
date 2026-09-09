<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Transactions\Domain\Transaction;

final readonly class PresentTransaction
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    public function one(Transaction $transaction): TransactionView
    {
        return $this->many([$transaction])[0];
    }

    /**
     * @param list<Transaction> $transactions
     *
     * @return list<TransactionView>
     */
    public function many(array $transactions): array
    {
        if ([] === $transactions) {
            return [];
        }

        $workspace = $transactions[0]->workspace;
        $categoryIds = [];
        foreach ($transactions as $transaction) {
            if ($transaction->workspace->id !== $workspace->id) {
                throw new \UnexpectedValueException('A transaction page cannot mix workspaces.');
            }
            foreach ($transaction->splits as $split) {
                $categoryIds[] = $split->categoryId;
            }
        }

        $labels = $this->categories->labelsByIds($workspace, array_values(array_unique($categoryIds)));

        return array_map(
            static fn (Transaction $transaction): TransactionView => TransactionView::fromTransaction($transaction, $labels),
            $transactions,
        );
    }
}
