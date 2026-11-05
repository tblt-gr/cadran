<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\TransactionFacet;

/** How many transactions each option of every section of the filter column would keep. */
final readonly class TransactionFacets
{
    /** @param array<string, array<string, int>> $counts by facet value, then by option; an option no transaction holds is absent */
    public function __construct(private array $counts)
    {
    }

    /** @return array<string, int> */
    public function of(TransactionFacet $facet): array
    {
        return $this->counts[$facet->value] ?? [];
    }
}
