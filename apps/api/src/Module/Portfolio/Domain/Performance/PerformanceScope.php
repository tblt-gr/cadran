<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

enum PerformanceScope: string
{
    case MONTH = 'MONTH';
    case YEAR = 'YEAR';
    case INCEPTION = 'INCEPTION';
}
