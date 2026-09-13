<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

enum RuleTextOperator: string
{
    case EQUALS = 'EQUALS';
    case CONTAINS = 'CONTAINS';
    case REGEX = 'REGEX';
}
