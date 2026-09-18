<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Reconciliation;

/** What the owner decides once a closing balance has been compared to the movements. */
enum ReconciliationResolution: string
{
    /** The books already agree: the discrepancy is exactly zero. */
    case MATCH = 'MATCH';

    /** Accept a non-zero discrepancy as it stands; nothing is written to the ledger. */
    case OVERRIDE = 'OVERRIDE';

    /** Record an ADJUSTMENT movement equal to the discrepancy so the books agree. */
    case ADJUST = 'ADJUST';
}
