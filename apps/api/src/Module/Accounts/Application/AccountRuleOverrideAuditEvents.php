<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final class AccountRuleOverrideAuditEvents
{
    public const string RECORDED = 'account_rule_override.recorded';
    public const string WITHDRAWN = 'account_rule_override.withdrawn';
    public const string ENTITY = 'account_rule_override';
}
