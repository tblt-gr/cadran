<?php

declare(strict_types=1);

namespace App\Module\Import\Domain;

/**
 * Why a workbook element is excluded or rejected. A code plus a sheet and a
 * cell coordinate is everything the console prints by default: never a label
 * or an amount.
 */
enum ReasonCode: string
{
    case UNKNOWN_LAYOUT = 'UNKNOWN_LAYOUT';
    case UNKNOWN_SHEET_NAME = 'UNKNOWN_SHEET_NAME';
    case UNMAPPED_LABEL = 'UNMAPPED_LABEL';
    case UNMAPPED_ACCOUNT = 'UNMAPPED_ACCOUNT';
    case AMOUNT_WITHOUT_LABEL = 'AMOUNT_WITHOUT_LABEL';
    /** Excluded, not rejected. */
    case LABEL_WITHOUT_AMOUNT = 'LABEL_WITHOUT_AMOUNT';
    /** Excluded, not rejected. */
    case ZERO_AMOUNT = 'ZERO_AMOUNT';
    case FORMULA_NOT_SUPPORTED = 'FORMULA_NOT_SUPPORTED';
    case FORMULA_REFERENCE = 'FORMULA_REFERENCE';
    case CACHED_VALUE_MISMATCH = 'CACHED_VALUE_MISMATCH';
    case PRECISION_EXCEEDED = 'PRECISION_EXCEEDED';
    case NEGATIVE_INCOME = 'NEGATIVE_INCOME';
    case UNLINKABLE_REFUND = 'UNLINKABLE_REFUND';
    case AMBIGUOUS_QUANTITY = 'AMBIGUOUS_QUANTITY';
    case ACCOUNT_OPENED_AFTER_DATE = 'ACCOUNT_OPENED_AFTER_DATE';
    case PERIOD_CLOSED = 'PERIOD_CLOSED';
    case YEAR_ALREADY_IMPORTED = 'YEAR_ALREADY_IMPORTED';
    case LATER_YEAR_ALREADY_IMPORTED = 'LATER_YEAR_ALREADY_IMPORTED';
    case ARCHIVE_REFUSED = 'ARCHIVE_REFUSED';
    /**
     * Excluded when a whole month sheet starts after today; a rejection (by the
     * issue) when a record would still be booked after today.
     */
    case MONTH_NOT_ENDED = 'MONTH_NOT_ENDED';
    /** Excluded: the owner's mapping skips this cell. */
    case SKIPPED_BY_MAPPING = 'SKIPPED_BY_MAPPING';

    /** A rejection aborts the year; an exclusion is only reported. */
    public function isRejection(): bool
    {
        return !in_array($this, [self::LABEL_WITHOUT_AMOUNT, self::ZERO_AMOUNT, self::MONTH_NOT_ENDED, self::SKIPPED_BY_MAPPING], true);
    }
}
