<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Transactions\Domain\TransactionFacet;
use App\Module\Transactions\Domain\TransactionFacetScope;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;

/** Reduces grouped rows into section counts, admitting only combinations allowed by its filters. */
final readonly class TransactionFacetTally
{
    /**
     * The name, in a tally row, of the column a facet counts by; null for the facets of the splits.
     */
    public static function column(TransactionFacet $facet): ?string
    {
        return match ($facet) {
            TransactionFacet::ACCOUNT => 'account_id',
            TransactionFacet::NATURE => 'nature',
            TransactionFacet::STATE => 'state',
            TransactionFacet::SOURCE => 'source',
            TransactionFacet::CATEGORY, TransactionFacet::AXIS => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $tally
     *
     * @return array<string, int>
     */
    public static function admitted(array $tally, TransactionFacetScope $scope): array
    {
        $filters = $scope->filters;
        $allowed = [
            // PostgreSQL prints a uuid in lower case; an identifier is matched whatever case it was written in.
            'account_id' => array_map(strtolower(...), $filters->accountIds),
            'nature' => array_map(static fn (TransactionNature $nature): string => $nature->value, $filters->natures),
            'source' => array_map(static fn (TransactionSource $source): string => $source->value, $filters->sources),
            'state' => array_map(static fn (TransactionState $state): string => $state->value, $filters->states),
        ];
        $category = TransactionFacet::CATEGORY === $scope->facet;
        $column = $category ? 'category_id' : (self::column($scope->facet) ?? throw new \LogicException('An axis is not a transaction column.'));

        $counts = [];
        foreach ($tally as $combination) {
            foreach ($allowed as $key => $values) {
                // An empty list does not restrict; a filtered column keeps the combinations whose value is listed.
                if ([] !== $values && !in_array(TransactionRow::text($combination[$key] ?? null), $values, true)) {
                    continue 2;
                }
            }
            if (null === ($combination[$column] ?? null)) {
                continue;
            }
            $matches = (int) TransactionRow::text($combination[$category ? 'split_matches' : 'matches'] ?? null);
            if ($matches > 0) {
                $value = TransactionRow::text($combination[$column]);
                $counts[$value] = ($counts[$value] ?? 0) + $matches;
            }
        }

        return $counts;
    }
}
