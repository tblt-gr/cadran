<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum InstrumentSource: string
{
    case MANUAL = 'MANUAL';
    case IMPORT = 'IMPORT';
}
