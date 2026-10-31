<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

/** Observed returns and the contract's full-life capital gain have distinct starting evidence. */
enum PerformanceHistoryStart
{
    case FIRST_OBSERVATION;
    case OPENING;
}
