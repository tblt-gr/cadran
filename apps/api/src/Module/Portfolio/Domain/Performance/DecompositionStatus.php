<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

enum DecompositionStatus: string
{
    case COMPLETE = 'COMPLETE';
    case UNAVAILABLE = 'UNAVAILABLE';
}
