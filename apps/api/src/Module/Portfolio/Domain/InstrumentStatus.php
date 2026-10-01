<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum InstrumentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
