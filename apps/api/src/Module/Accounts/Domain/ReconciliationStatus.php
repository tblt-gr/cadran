<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Whether this snapshot has been compared to movements. BAL-001 stores the
 * status; the comparison itself arrives with TX-007. A newly recorded figure
 * starts unreconciled rather than pretending the books already match.
 */
enum ReconciliationStatus: string
{
    case UNRECONCILED = 'UNRECONCILED';
    case RECONCILED = 'RECONCILED';
}
