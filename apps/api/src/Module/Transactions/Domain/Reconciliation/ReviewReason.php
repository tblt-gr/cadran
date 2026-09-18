<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Reconciliation;

/**
 * Why a pending transaction waits for a human before it can be booked.
 *
 * A row carrying one of these is an incoming movement the matching rule
 * refused to attribute. It stays out of every aggregate until someone resolves
 * it, which is the whole point: a wrong automatic settlement would count one
 * real movement twice.
 */
enum ReviewReason: string
{
    /** Several pending rows fit the movement equally well. */
    case AMBIGUOUS_MATCH = 'AMBIGUOUS_MATCH';

    /** No pending row fits the movement at all. */
    case NO_MATCH = 'NO_MATCH';
}
