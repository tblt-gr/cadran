<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * Refuses editing or voiding a transfer leg through the single-transaction
 * endpoints: the pair is indivisible, so only the transfer's own endpoint may
 * change it.
 */
final class TransactionBelongsToTransfer extends \RuntimeException
{
    public function __construct(public readonly string $transferId)
    {
        parent::__construct('A transfer leg can only be edited or voided through its transfer.');
    }
}
