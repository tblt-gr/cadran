<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\Transaction;

final readonly class TransactionSummaryView
{
    public function __construct(
        public string $id,
        public string $bookedOn,
        public string $label,
        public string $amount,
        public string $assetCode,
        public string $state,
    ) {
    }

    public static function fromTransaction(Transaction $transaction): self
    {
        return new self(
            $transaction->id,
            $transaction->bookedOn->format('Y-m-d'),
            $transaction->rawLabel,
            $transaction->amount->value->toString(),
            $transaction->amount->asset->toString(),
            $transaction->state->value,
        );
    }
}
