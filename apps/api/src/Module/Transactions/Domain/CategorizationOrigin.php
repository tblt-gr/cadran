<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/** Who chose a split's category: a person, or a categorisation rule. A person's choice is authoritative. */
enum CategorizationOrigin: string
{
    case MANUAL = 'MANUAL';
    case RULE = 'RULE';
}
