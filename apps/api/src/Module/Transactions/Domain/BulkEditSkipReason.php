<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/**
 * Why a transaction of a bulk edit was left untouched, whole or for its
 * category only. The category reasons mirror {@see BulkCategorizationSkipReason}.
 */
enum BulkEditSkipReason: string
{
    case NOT_FOUND = 'NOT_FOUND';
    case NO_LONGER_MATCHING = 'NO_LONGER_MATCHING';
    case TERMINAL = 'TERMINAL';
    case TRANSFER_LEG = 'TRANSFER_LEG';
    case HAS_REFUNDS = 'HAS_REFUNDS';
    case CLOSED_PERIOD = 'CLOSED_PERIOD';
    case LABEL_REQUIRED = 'LABEL_REQUIRED';
    case REFUND = 'REFUND';
    case SPLIT = 'SPLIT';
    case SIGN_MISMATCH = 'SIGN_MISMATCH';
    case ALREADY_IN_CATEGORY = 'ALREADY_IN_CATEGORY';
}
