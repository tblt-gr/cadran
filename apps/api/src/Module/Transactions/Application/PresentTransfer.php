<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\Transfer;

final readonly class PresentTransfer
{
    public function __construct(
        private TransactionRepository $transactions,
        private PresentTransaction $presentTransaction,
    ) {
    }

    public function one(Transfer $transfer): TransferView
    {
        $source = $this->leg($transfer, $transfer->sourceTransactionId);
        $target = $this->leg($transfer, $transfer->targetTransactionId);
        $fee = null === $transfer->feeTransactionId ? null : $this->leg($transfer, $transfer->feeTransactionId);

        return new TransferView(
            id: $transfer->id,
            source: $this->presentTransaction->one($source),
            target: $this->presentTransaction->one($target),
            fee: null === $fee ? null : $this->presentTransaction->one($fee),
            exchangeRate: $transfer->exchangeRate?->toString(),
            version: $transfer->version,
            createdAt: $transfer->createdAt->format(DATE_ATOM),
            updatedAt: $transfer->updatedAt->format(DATE_ATOM),
            voidedAt: $transfer->voidedAt?->format(DATE_ATOM),
        );
    }

    private function leg(Transfer $transfer, string $transactionId): \App\Module\Transactions\Domain\Transaction
    {
        return $this->transactions->find($transfer->workspace, $transactionId)
            ?? throw new \UnexpectedValueException('A transfer leg is missing its transaction.');
    }
}
