<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Reconciliation;

/**
 * Why a balance discrepancy could not be produced.
 *
 * A published reason replaces the figure; it never accompanies one. Defaulting
 * the missing side to zero would hide a real gap behind a reassuring 0.
 */
enum ReconciliationReason: string
{
    /** No active balance snapshot exists on or before the day preceding the period. */
    case MISSING_OPENING_BALANCE = 'MISSING_OPENING_BALANCE';

    /** The observed closing figure has been superseded or is not the latest one of its day. */
    case STALE_CLOSING_BALANCE = 'STALE_CLOSING_BALANCE';

    /** Opening, closing and movements are not all denominated in the same asset. */
    case MIXED_ASSETS = 'MIXED_ASSETS';
}
