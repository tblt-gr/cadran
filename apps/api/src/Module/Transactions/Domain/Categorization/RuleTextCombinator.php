<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

enum RuleTextCombinator: string
{
    case AND = 'AND';
    case OR = 'OR';
}
