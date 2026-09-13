<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

enum RuleDeactivationReason: string
{
    case USER = 'USER';
    case PATTERN_BUDGET_EXCEEDED = 'PATTERN_BUDGET_EXCEEDED';
    case CATEGORY_ARCHIVED = 'CATEGORY_ARCHIVED';
}
