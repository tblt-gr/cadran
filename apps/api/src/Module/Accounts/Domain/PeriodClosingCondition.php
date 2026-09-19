<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * What can stand between a month and its closing. Each one is overridable, but
 * only by naming it: a blanket "force" would hide which check was waived.
 */
enum PeriodClosingCondition: string
{
    case UNRECONCILED_ACCOUNT = 'UNRECONCILED_ACCOUNT';
    case UNEXPLAINED_DISCREPANCY = 'UNEXPLAINED_DISCREPANCY';
    case PENDING_TRANSACTIONS = 'PENDING_TRANSACTIONS';
}
