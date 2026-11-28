<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Infrastructure\Persistence\DbalValue;
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
     * The groups of the tally that the account, nature, source and state filters of $scope admit.
     *
     * @param list<array<string, mixed>> $tally
     *
     * @return list<array<string, mixed>>
     */
    public static function matching(array $tally, TransactionFacetScope $scope): array
    {
        $filters = $scope->filters;
        $allowed = [
            // PostgreSQL prints a uuid in lower case; an identifier is matched whatever case it was written in.
            'account_id' => array_map(strtolower(...), $filters->accountIds),
            'nature' => array_map(static fn (TransactionNature $nature): string => $nature->value, $filters->natures),
            'source' => array_map(static fn (TransactionSource $source): string => $source->value, $filters->sources),
            'state' => array_map(static fn (TransactionState $state): string => $state->value, $filters->states),
        ];

        $rows = [];
        foreach ($tally as $combination) {
            foreach ($allowed as $key => $values) {
                // An empty list does not restrict; a filtered column keeps the combinations whose value is listed.
                if ([] !== $values && !in_array(DbalValue::text($combination[$key] ?? null), $values, true)) {
                    continue 2;
                }
            }
            $rows[] = $combination;
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $tally
     *
     * @return array<string, int>
     */
    public static function admitted(array $tally, TransactionFacetScope $scope): array
    {
        $category = TransactionFacet::CATEGORY === $scope->facet;
        $column = $category ? 'category_id' : (self::column($scope->facet) ?? throw new \LogicException('An axis is not a transaction column.'));

        $counts = [];
        foreach (self::matching($tally, $scope) as $combination) {
            if (null === ($combination[$column] ?? null)) {
                continue;
            }
            $matches = (int) DbalValue::text($combination[$category ? 'split_matches' : 'matches'] ?? null);
            if ($matches > 0) {
                $value = DbalValue::text($combination[$column]);
                $counts[$value] = ($counts[$value] ?? 0) + $matches;
            }
        }

        return $counts;
    }

    /**
     * The axes of the splits the scope admits, spelled out axis by axis: a split carrying two
     * axes counts for each. Several splits of a transaction may carry one axis, so this counts
     * splits; the caller takes the repeats back.
     *
     * @param list<array<string, mixed>> $tally
     *
     * @return array<string, int>
     */
    public static function axes(array $tally, TransactionFacetScope $scope): array
    {
        $byAxis = [];
        foreach (self::matching($tally, $scope) as $combination) {
            if (null === ($combination['axes'] ?? null)) {
                continue;
            }
            $matches = (int) DbalValue::text($combination['split_matches'] ?? null);
            foreach (self::axisList(DbalValue::text($combination['axes'])) as $axis) {
                $byAxis[$axis] = ($byAxis[$axis] ?? 0) + $matches;
            }
        }

        return $byAxis;
    }

    /**
     * The axes of a split, each once: the table only requires them to be among the six, so a row
     * written around the domain may list one twice, which still makes one split carrying it.
     *
     * @return list<string>
     */
    public static function axisList(string $json): array
    {
        $axes = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($axes)) {
            throw new \UnexpectedValueException('Expected a list of analytic axes.');
        }

        return array_values(array_unique(array_filter($axes, is_string(...))));
    }

    /**
     * Whether no section is narrowed by a category, an axis or the to-categorise queue.
     *
     * @param list<TransactionFacetScope> $scopes
     */
    public static function withoutSplitFilter(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ([] !== $scope->filters->categoryIds || [] !== $scope->filters->axes || $scope->filters->categorizationNone) {
                return false;
            }
        }

        return true;
    }
}
