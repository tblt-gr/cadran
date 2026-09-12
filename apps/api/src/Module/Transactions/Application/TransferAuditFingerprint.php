<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Domain\Transfer;

/**
 * Structural facts only, never an amount or a label: leg count, whether the
 * two legs carry different assets, whether a fee exists, state and version.
 */
final readonly class TransferAuditFingerprint
{
    /** @return array<string, scalar|null> */
    public static function of(Transfer $transfer, bool $crossAsset, TransactionState $state): array
    {
        return [
            'legCount' => null === $transfer->feeTransactionId ? 2 : 3,
            'crossAsset' => $crossAsset,
            'hasFee' => null !== $transfer->feeTransactionId,
            'state' => $state->value,
            'version' => $transfer->version,
        ];
    }
}
