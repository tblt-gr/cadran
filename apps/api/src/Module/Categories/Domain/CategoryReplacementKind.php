<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

enum CategoryReplacementKind: string
{
    /** Redirects the whole history of the source and archives it. */
    case MERGE = 'MERGE';

    /** Redirects the source from a date onwards and leaves it usable before it. */
    case REPLACEMENT = 'REPLACEMENT';
}
