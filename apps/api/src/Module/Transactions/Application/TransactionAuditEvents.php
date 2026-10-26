<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class TransactionAuditEvents
{
    public const string ENTITY = 'transaction';
    public const string CREATED = 'transaction.created';
    public const string UPDATED = 'transaction.updated';
    public const string VOIDED = 'transaction.voided';
    public const string BULK_CATEGORIZED = 'transaction.bulk_categorized';
    public const string BULK_CATEGORIZATION_RUN = 'transaction.bulk_categorization_run';
    public const string BATCH_ENTITY = 'transaction_batch';
    public const string DUPLICATED = 'transaction.duplicated';
    public const string RECONCILED = 'transaction.reconciled';
    public const string ACCOUNT_RECONCILED = 'account.reconciled';
    public const string RECONCILIATION_OVERRIDDEN = 'account.reconciliation_overridden';
}
