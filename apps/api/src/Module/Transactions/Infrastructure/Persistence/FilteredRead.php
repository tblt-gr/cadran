<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

/**
 * The statements {@see DbalTransactionFilterReader} runs over the transactions a filter narrows.
 * The first three serve the listing; the others tally what the facet counts add up, each
 * returning a few dozen rows however large the ledger is.
 */
enum FilteredRead
{
    /** A page of the matching transactions, newest first. */
    case ROWS;
    /** The page that follows a keyset position. */
    case ROWS_AFTER;
    /** How many transactions match. */
    case COUNT;
    /** The matching transactions by account, nature, source and state together. */
    case COLUMN_TALLY;
    /** The column tally and category splits in one pass, counting a transaction only on its first split. */
    case COLUMN_CATEGORY_TALLY;
    /** The splits of the matching transactions by category. */
    case CATEGORY_TALLY;
    /** The splits of the matching transactions by axis list. */
    case AXES_TALLY;
    /** The splits of the matching transactions by category and axis list together. */
    case CATEGORY_AXES_TALLY;
    /** The splits whose axis an earlier split of their transaction already carries, by axis. */
    case REPEATED_AXES;
    /** Each category with the transactions that have a split anywhere below it, once. */
    case ROLLED_UP;
}
