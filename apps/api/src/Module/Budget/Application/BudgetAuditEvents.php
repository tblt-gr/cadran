<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

final class BudgetAuditEvents
{
    public const string PLAN_CREATED = 'budget_plan.created';
    public const string PLAN_UPDATED = 'budget_plan.updated';
    public const string PLAN_ACTIVATED = 'budget_plan.activated';
    public const string PLAN_CLOSED = 'budget_plan.closed';
    public const string PLAN_ENTITY = 'budget_plan';

    public const string TARGET_CREATED = 'budget_target.created';
    public const string TARGET_UPDATED = 'budget_target.updated';
    public const string TARGET_REMOVED = 'budget_target.removed';
    public const string TARGET_ENTITY = 'budget_target';
}
