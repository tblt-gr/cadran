<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

/** Closed set so that a provider or an import can be added without a schema redesign. */
enum ValuationSource: string
{
    case MANUAL = 'MANUAL';
    case IMPORT = 'IMPORT';
}
