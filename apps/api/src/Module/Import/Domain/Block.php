<?php

declare(strict_types=1);

namespace App\Module\Import\Domain;

/** The origin of a movement inside a workbook, as written in a source reference. */
enum Block: string
{
    case INC = 'INC';
    case FIX = 'FIX';
    case VAR = 'VAR';
    case SAV = 'SAV';
    case ACC = 'ACC';
    case PEA = 'PEA';
    case CTO = 'CTO';
    case OPEN = 'OPEN';
}
