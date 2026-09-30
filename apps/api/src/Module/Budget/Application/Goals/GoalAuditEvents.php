<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final class GoalAuditEvents
{
    public const string CREATED = 'goal.created';
    public const string UPDATED = 'goal.updated';
    public const string CORRECTED = 'goal.corrected';
    public const string CLOSED = 'goal.closed';
    public const string ENTITY = 'goal';
}
