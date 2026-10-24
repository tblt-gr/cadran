<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final class AccountBalanceAuditEvents
{
    public const string RECORDED = 'account_balance_snapshot.recorded';
    public const string CORRECTED = 'account_balance_snapshot.corrected';
    public const string SUPERSEDED = 'account_balance_snapshot.superseded';
    public const string ENTITY = 'account_balance_snapshot';
}
