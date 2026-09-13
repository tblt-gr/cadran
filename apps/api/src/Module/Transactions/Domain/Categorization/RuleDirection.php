<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

enum RuleDirection: string
{
    case IN = 'IN';
    case OUT = 'OUT';
}
