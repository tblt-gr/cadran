<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * The verdict of comparing a measured figure to a ceiling.
 *
 * EXCEEDED is a warning, never a refusal: credited interest, a historical
 * import, or a product that keeps paying above the Livret A figure (a Livret
 * Bleu) may sit over the amount. The scale, not this status, decides what
 * the excess earns.
 */
enum CeilingCheckStatus: string
{
    case WITHIN = 'WITHIN';
    case EXCEEDED = 'EXCEEDED';
    case NOT_COMPARABLE = 'NOT_COMPARABLE';
    case UNSETTLED = 'UNSETTLED';
}
