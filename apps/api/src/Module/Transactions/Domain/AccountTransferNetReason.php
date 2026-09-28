<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/**
 * Why one account's monthly transfer net could not be produced.
 *
 * A published reason replaces the figure; it never accompanies one. Defaulting
 * an unresolved pair to zero would hide a real data gap behind a reassuring 0.
 */
enum AccountTransferNetReason: string
{
    /** One of the pair's two legs is missing an account, amount, asset, state or booking date. */
    case INCOMPLETE_TRANSFER_PAIR = 'INCOMPLETE_TRANSFER_PAIR';

    /** The pair's two legs disagree on state or booking date. */
    case MISMATCHED_TRANSFER_PAIR = 'MISMATCHED_TRANSFER_PAIR';

    /** The pair's two legs are not denominated in the same asset. */
    case MIXED_ASSETS = 'MIXED_ASSETS';
}
