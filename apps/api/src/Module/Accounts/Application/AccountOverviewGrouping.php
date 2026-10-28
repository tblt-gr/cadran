<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * How the overview cuts its tables: one per root group that rolls its whole
 * subtree up, or one per primary group holding its direct accounts.
 */
enum AccountOverviewGrouping: string
{
    case PARENT = 'PARENT';
    case PRIMARY = 'PRIMARY';
}
