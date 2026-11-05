<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/** One section of the filter column, with the filters its counts are taken under. */
final readonly class TransactionFacetScope
{
    public function __construct(
        public TransactionFacet $facet,
        public TransactionFilters $filters,
    ) {
    }
}
