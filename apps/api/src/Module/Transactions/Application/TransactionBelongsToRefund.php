<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final class TransactionBelongsToRefund extends \DomainException
{
    public function __construct(public readonly string $originalTransactionId)
    {
        parent::__construct('A linked refund must be voided and recreated instead of edited.');
    }
}
