<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/** A durable relationship; the two transaction rows are never merged. */
final readonly class TransactionRefund
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $refundTransactionId,
        public string $originalTransactionId,
        public \DateTimeImmutable $createdAt,
    ) {
        if ($refundTransactionId === $originalTransactionId) {
            throw new InvalidTransaction('A refund cannot name itself as its original.');
        }
    }
}
