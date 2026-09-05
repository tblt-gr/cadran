<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final class AccountGroupAuditEvents
{
    public const string CREATED = 'account_group.created';
    public const string UPDATED = 'account_group.updated';
    public const string ARCHIVED = 'account_group.archived';
    public const string ENTITY = 'account_group';
}
