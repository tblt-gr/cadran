<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/** Why a transaction of a bulk recategorisation was left untouched. */
enum BulkCategorizationSkipReason: string
{
    case NOT_FOUND = 'NOT_FOUND';
    case NO_LONGER_MATCHING = 'NO_LONGER_MATCHING';
    case TRANSFER_LEG = 'TRANSFER_LEG';
    case REFUND = 'REFUND';
    case HAS_REFUNDS = 'HAS_REFUNDS';
    case TERMINAL = 'TERMINAL';
    case SPLIT = 'SPLIT';
    case CLOSED_PERIOD = 'CLOSED_PERIOD';
    case SIGN_MISMATCH = 'SIGN_MISMATCH';
    case ALREADY_IN_CATEGORY = 'ALREADY_IN_CATEGORY';
}
