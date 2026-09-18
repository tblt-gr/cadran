<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Whether this snapshot has been compared to movements. A newly recorded
 * figure starts unreconciled rather than pretending the books already match;
 * the Transactions module flips it to reconciled once the discrepancy is
 * zero or has been explicitly accepted or adjusted.
 */
enum ReconciliationStatus: string
{
    case UNRECONCILED = 'UNRECONCILED';
    case RECONCILED = 'RECONCILED';
}
