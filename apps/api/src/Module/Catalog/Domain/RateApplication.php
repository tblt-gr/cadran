<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * How the brackets of a rate scale combine over an amount.
 *
 * A scale is meaningless without it: the same two brackets pay a different
 * interest depending on whether the reached bracket applies to everything or
 * only to the slice it covers. A scale of 4 % up to 10 000 then 2 % above pays
 * 500 on a balance of 15 000 read as MARGINAL, and 300 read as
 * FLAT_BY_BRACKET — the same figures, two answers, so the mode is recorded
 * beside the brackets rather than assumed by whoever reads them.
 *
 * A single-bracket scale, which is what one published rate resolves to, gives
 * the same result under both modes.
 */
enum RateApplication: string
{
    /** Each slice is remunerated at the rate of the bracket covering it. */
    case MARGINAL = 'MARGINAL';
    /** The bracket the amount falls in sets one rate applied to the whole amount. */
    case FLAT_BY_BRACKET = 'FLAT_BY_BRACKET';
}
