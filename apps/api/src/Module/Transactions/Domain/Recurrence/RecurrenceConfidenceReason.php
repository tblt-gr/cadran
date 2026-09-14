<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

/**
 * Why a candidate carries no confidence. It replaces the figure the rules
 * cannot produce, so an interface can explain the blank instead of showing a
 * zero the history never justified.
 */
enum RecurrenceConfidenceReason: string
{
    /** The observations are regular enough to propose, but no row of the qualitative table covers their count and spread. */
    case UNCLASSIFIED_GAP_SPREAD = 'UNCLASSIFIED_GAP_SPREAD';
}
