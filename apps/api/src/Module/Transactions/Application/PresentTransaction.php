<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransferRepository;

final readonly class PresentTransaction
{
    public function __construct(
        private CategoryRepository $categories,
        private TransferRepository $transfers,
        private RefundRepository $refunds,
        private TransactionRepository $transactions,
    ) {
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

        $identities = $this->categories->identitiesByIds($workspace, array_values(array_unique($categoryIds)));
        $transferIds = array_map(static fn (Transaction $transaction): string => $transaction->id, $transactions);
        $markers = $this->transfers->markersForLegs($workspace, $transferIds);
        $refundOriginals = [];
        $refundedAmounts = [];
        foreach ($transactions as $transaction) {
            $refund = $this->refunds->findByRefundTransactionId($workspace, $transaction->id);
            if (null !== $refund) {
                $refundOriginals[$transaction->id] = $this->transactions->find($workspace, $refund->originalTransactionId);
            }
            if ([] !== $this->refunds->findByOriginalTransactionId($workspace, $transaction->id)) {
                $refundedAmounts[$transaction->id] = $this->refunds->refundedAmount($workspace, $transaction->id);
            }
        }

        return array_map(
            static fn (Transaction $transaction): TransactionView => TransactionView::fromTransaction(
                $transaction, $identities, $markers[$transaction->id] ?? null,
                $refundOriginals[$transaction->id] ?? null, $refundedAmounts[$transaction->id] ?? null,
            ),
            $transactions,
        );
    }
}
