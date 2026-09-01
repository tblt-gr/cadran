<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * The rule a presentation boundary applies when it shows fewer decimals than
 * are stored. It is declared per asset and never inferred: an amount is
 * rounded only where a named, documented boundary says so.
 */
enum RoundingMode: string
{
    /** Away from zero on a tie. The French accounting and retail convention. */
    case HALF_UP = 'HALF_UP';
    /** To the nearest even digit on a tie, for statistical aggregates. */
    case HALF_EVEN = 'HALF_EVEN';
    /** Towards zero, for units that must never be shown as more than held. */
    case DOWN = 'DOWN';
}
