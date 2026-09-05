<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Why a net-worth figure, a delta or a change rate could not be produced.
 *
 * A published reason replaces the figure; it never accompanies one. Inventing
 * `0` or `0 %` here would make an unvalued portfolio look empty and a flat
 * year look measured.
 */
enum NetWorthReason: string
{
    /** At least one eligible account has no valuation on the requested day. */
    case MISSING_VALUATION = 'MISSING_VALUATION';

    /** No account is included in net worth and open on the requested day. */
    case NO_ELIGIBLE_ACCOUNT = 'NO_ELIGIBLE_ACCOUNT';

    /**
     * Eligible accounts are denominated in more than one asset. Conversion is
     * out of scope, so a sum across units would be a fabricated number.
     */
    case MIXED_ASSETS = 'MIXED_ASSETS';

    /** The compared base is exactly zero: a rate would divide by zero. */
    case ZERO_BASE = 'ZERO_BASE';

    /**
     * The compared base is negative. A percentage against it reads backwards —
     * debt shrinking from -100 to -50 is not "+50 %" of anything a reader
     * would understand — so the rate stays null and the delta speaks alone.
     */
    case NEGATIVE_BASE = 'NEGATIVE_BASE';
}
