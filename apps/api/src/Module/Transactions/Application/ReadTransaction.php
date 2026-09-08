<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Transactions\Domain\TransactionRepository;

final readonly class ReadTransaction
{
    public function __construct(private CallerWorkspace $caller, private TransactionRepository $transactions)
    {
    }

    public function __invoke(string $id): TransactionView
    {
        $transaction = $this->transactions->find($this->caller->resolve(), $id);
        if (null === $transaction) {
            throw new TransactionNotFound();
        }

        return TransactionView::fromTransaction($transaction);
    }
}
