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
            'bookedOn' => $transaction->bookedOn->format('Y-m-d'),
            'splitCount' => count($transaction->splits),
            'categorised' => [] !== $transaction->splits,
            'version' => $transaction->version,
        ];
    }

    /**
     * The after-side fingerprint of an edit or a reconciliation settlement,
     * with one added signal a plain {@see self::of()} snapshot cannot carry
     * on its own: whether the figure itself moved between the two versions.
     * Amounts have too little entropy for any digest of one to stay
     * non-reversible, so this never encodes the value, only the fact that it
     * differs — which is what a reconciliation anomaly or a mistaken edit is
     * read from.
     *
     * @return array<string, scalar|null>
     */
    public static function changed(Transaction $before, Transaction $after): array
    {
        return [
            ...self::of($after),
            'figureChanged' => 0 !== $before->amount->value->compareTo($after->amount->value),
        ];
    }
}
