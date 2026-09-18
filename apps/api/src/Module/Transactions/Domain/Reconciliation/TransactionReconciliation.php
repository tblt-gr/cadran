<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Reconciliation;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\InvalidTransaction;

/**
 * The durable link a resolved review leaves behind: the reviewed row was
 * voided because this other row is the same real movement. The two rows are
 * never merged, exactly as a refund never rewrites its original.
 */
final readonly class TransactionReconciliation
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $reviewedTransactionId,
        public string $matchedTransactionId,
        public \DateTimeImmutable $createdAt,
    ) {
        if ($reviewedTransactionId === $matchedTransactionId) {
            throw new InvalidTransaction('A reviewed transaction cannot settle itself.');
        }
    }
}
