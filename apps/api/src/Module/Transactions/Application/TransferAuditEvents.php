<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class TransferAuditEvents
{
    public const string ENTITY = 'transfer';
    public const string CREATED = 'transfer.created';
    public const string UPDATED = 'transfer.updated';
    public const string VOIDED = 'transfer.voided';
}
