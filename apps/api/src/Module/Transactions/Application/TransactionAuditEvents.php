<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class TransactionAuditEvents
{
    public const string ENTITY = 'transaction';
    public const string CREATED = 'transaction.created';
    public const string UPDATED = 'transaction.updated';
    public const string VOIDED = 'transaction.voided';
    public const string DUPLICATED = 'transaction.duplicated';
    public const string RECONCILED = 'transaction.reconciled';
}
