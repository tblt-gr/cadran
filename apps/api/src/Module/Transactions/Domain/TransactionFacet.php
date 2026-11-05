<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/**
 * A section of the transaction filter column that can show how many transactions each of
 * its options would keep. The value is the name of the filter the section drives, which is
 * also the key of its counts in the facet response.
 */
enum TransactionFacet: string
{
    case ACCOUNT = 'accountId';
    case CATEGORY = 'categoryId';
    case NATURE = 'nature';
    case STATE = 'state';
    case SOURCE = 'source';
    case AXIS = 'axis';
}
