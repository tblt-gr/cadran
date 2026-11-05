<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Transactions\Application\TransactionFacets;
use App\Module\Transactions\Domain\TransactionFacet;

final readonly class TransactionFacetsRepresentation
{
    /** @return array<string, array<string, int>|\stdClass> */
    public static function of(TransactionFacets $facets): array
    {
        $representation = [];
        foreach (TransactionFacet::cases() as $facet) {
            $counts = $facets->of($facet);
            // A section nothing matches stays a JSON object: an empty PHP array would encode as a list.
            $representation[$facet->value] = [] === $counts ? new \stdClass() : $counts;
        }

        return $representation;
    }
}
