<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final class AccountAuditEvents
{
    public const string CREATED = 'account.created';
    public const string UPDATED = 'account.updated';
    public const string CLOSED = 'account.closed';
    public const string REOPENED = 'account.reopened';
    public const string ARCHIVED = 'account.archived';
    public const string ENTITY = 'financial_account';
}
