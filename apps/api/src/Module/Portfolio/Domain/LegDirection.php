<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum LegDirection: string
{
    case IN = 'IN';
    case OUT = 'OUT';
}
