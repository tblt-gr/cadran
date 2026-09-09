<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\Transaction;

final readonly class TransactionAuditFingerprint
{
    /** @return array<string, scalar|null> */
    public static function of(Transaction $transaction): array
    {
        return [
            'nature' => $transaction->nature->value,
            'state' => $transaction->state->value,
            'source' => $transaction->source->value,
            'sign' => $transaction->amount->value->isNegative() ? -1 : 1,
            'scale' => $transaction->amount->value->scale(),
            'splitCount' => count($transaction->splits),
            'categorised' => [] !== $transaction->splits,
            'version' => $transaction->version,
        ];
    }
}
