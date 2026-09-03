<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * How the brackets of a rate scale combine over an amount.
 *
 * A scale is meaningless without it: the same two brackets pay a different
 * interest depending on whether the reached bracket applies to everything or
 * only to the slice it covers. Every product the catalogue ships today states
 * one rate for the whole balance; a tiered product adds its own mode here
 * rather than changing the shape resolved rates travel in.
 */
enum RateApplication: string
{
    /** One rate over the whole amount, whichever bracket it falls in. */
    case WHOLE_BALANCE = 'WHOLE_BALANCE';
}
