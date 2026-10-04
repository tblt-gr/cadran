<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

enum AnchorKind: string
{
    case VALUATION = 'VALUATION';
    case OPENING = 'OPENING';
}
