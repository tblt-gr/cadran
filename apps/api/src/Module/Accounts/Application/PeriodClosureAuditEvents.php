<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final class PeriodClosureAuditEvents
{
    public const string CLOSED = 'account_period_closure.closed';
    public const string REOPENED = 'account_period_closure.reopened';
    public const string ENTITY = 'account_period_closure';
}
