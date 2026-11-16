<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\CategorySubtrees;
use App\Module\Transactions\Domain\TransactionFacet;
use App\Module\Transactions\Domain\TransactionFacetScope;
use App\Module\Transactions\Domain\TransactionFilters;

/**
 * The facet counts of the filter column, from the tallies {@see DbalTransactionFilterReader} reads.
 *
 * They stay cheap on a ledger of 100 000 transactions: the four columns of a transaction are
 * tallied by one scan, and each section adds up the combinations its own filters admit; the
 * categories and the axes are tallied by one pass over the splits; and nothing sorts every split
 * to count distinct transactions, the duplicates being subtracted instead over the few
 * transactions that have several splits.
 */
final readonly class DbalTransactionFacetReader
{
    public function __construct(private DbalTransactionFilterReader $reader)
    {
    }

    /**
     * @param list<TransactionFacetScope> $scopes
     *
     * @return array<string, array<string, int>>
     *
     * @see \App\Module\Transactions\Domain\TransactionRepository::facetCounts()
     */
    public function facetCounts(WorkspaceScope $workspace, array $scopes, ?CategorySubtrees $subtrees = null): array
    {
        if ([] !== $scopes && TransactionFacetTally::withoutSplitFilter($scopes)) {
            return self::sorted($this->countTogether($workspace, $scopes, $subtrees));
        }

        $columns = array_values(array_filter($scopes, static fn (TransactionFacetScope $scope): bool => null !== TransactionFacetTally::column($scope->facet)));
        $splits = array_values(array_filter($scopes, static fn (TransactionFacetScope $scope): bool => null === TransactionFacetTally::column($scope->facet)));

        $splitGroups = self::sharing($splits, false);
        $counts = [];
        // The four columns of a transaction are tallied together whatever their own filters say.
        foreach (self::sharing($columns, true) as $group) {
            $category = null;
            // When an axis separates the split scopes, pair the category with the column scan
            // instead of reading the same axis/text filters twice. Keep a shared category/axis
            // scan when possible, and leave subtree rollups to their dedicated statement.
            if (null === $subtrees && count($splitGroups) > 1) {
                foreach ($splitGroups as $index => $candidate) {
                    if (1 === count($candidate) && TransactionFacet::CATEGORY === $candidate[0]->facet
                        && serialize($candidate[0]->filters->withoutColumnFilters()) === serialize($group[0]->filters->withoutColumnFilters())) {
                        $category = $candidate[0];
                        unset($splitGroups[$index]);
                        break;
                    }
                }
            }
            $counts = [...$counts, ...$this->countColumns($workspace, $group, $category)];
        }
        foreach ($splitGroups as $group) {
            $counts = [...$counts, ...$this->countSplits($workspace, $group, $subtrees)];
        }

        return self::sorted($counts);
    }

    /**
     * @param array<string, array<string, int>> $counts
     *
     * @return array<string, array<string, int>>
     */
    private static function sorted(array $counts): array
    {
        foreach ($counts as $facet => $values) {
            ksort($values, SORT_STRING);
            $counts[$facet] = $values;
        }

        return $counts;
    }

    /**
     * With no filter on the splits every section reads the same transactions: one scan tallies them
     * all. A split filter keeps the statements of {@see facetCounts()}: their semi-joins only visit
     * the transactions it keeps, where flagging each transaction in one scan read every split and
     * measured slower.
     *
     * @param non-empty-list<TransactionFacetScope> $scopes
     *
     * @return array<string, array<string, int>>
     */
    private function countTogether(WorkspaceScope $workspace, array $scopes, ?CategorySubtrees $subtrees): array
    {
        $tally = $this->reader->read(FilteredRead::FACET_TALLY, $workspace, $scopes[0]->filters->withoutColumnFilters());

        $counts = [];
        foreach ($scopes as $scope) {
            $counts[$scope->facet->value] = match (true) {
                TransactionFacet::AXIS === $scope->facet => $this->withoutRepeats($workspace, TransactionFacetTally::axes($tally, $scope), $scope->filters),
                TransactionFacet::CATEGORY === $scope->facet && null !== $subtrees => $this->countRolledUp($workspace, $scope->filters, $subtrees),
                default => TransactionFacetTally::admitted($tally, $scope),
            };
        }

        return $counts;
    }

    /**
     * The spelled-out axis counts less the splits whose axis an earlier split of the same
     * transaction already carries, so that a transaction counts once per axis.
     *
     * @param array<string, int> $byAxis
     *
     * @return array<string, int>
     */
    private function withoutRepeats(WorkspaceScope $workspace, array $byAxis, TransactionFilters $filters): array
    {
        foreach ($this->countRepeatedAxes($workspace, $filters) as $axis => $repeated) {
            $byAxis[$axis] = ($byAxis[$axis] ?? 0) - $repeated;
        }

        return array_filter($byAxis, static fn (int $count): bool => $count > 0);
    }

    /**
     * The scopes that can share statements: those counted under the very same filters, the
     * account, nature, source and state filters set aside when $ignoringColumnFilters.
     *
     * @param list<TransactionFacetScope> $scopes
     *
     * @return list<non-empty-list<TransactionFacetScope>>
     */
    private static function sharing(array $scopes, bool $ignoringColumnFilters): array
    {
        $groups = [];
        foreach ($scopes as $scope) {
            $key = serialize($ignoringColumnFilters ? $scope->filters->withoutColumnFilters() : $scope->filters);
            $groups[$key] = [...($groups[$key] ?? []), $scope];
        }

        return array_values($groups);
    }

    /**
     * One scan tallies the matching transactions by account, nature, source and state together
     * (a few dozen combinations), and each facet then adds up the combinations its own filters
     * admit. That is how a section can be counted without its own filter and yet cost no more
     * than one pass over the ledger.
     *
     * @param non-empty-list<TransactionFacetScope> $scopes the facets of the columns, which differ by their column filters only
     *
     * @return array<string, array<string, int>>
     */
    private function countColumns(WorkspaceScope $workspace, array $scopes, ?TransactionFacetScope $category): array
    {
        $tally = $this->reader->read(null === $category ? FilteredRead::COLUMN_TALLY : FilteredRead::COLUMN_CATEGORY_TALLY, $workspace, $scopes[0]->filters->withoutColumnFilters());

        $counts = [];
        foreach ($scopes as $scope) {
            $counts[$scope->facet->value] = TransactionFacetTally::admitted($tally, $scope);
        }
        if (null !== $category) {
            $counts[$category->facet->value] = TransactionFacetTally::admitted($tally, $category);
        }

        return $counts;
    }

    /**
     * The categories and the axes of the splits of the matching transactions.
     *
     * @param non-empty-list<TransactionFacetScope> $scopes the facets of the splits, counted under the same filters
     *
     * @return array<string, array<string, int>>
     */
    private function countSplits(WorkspaceScope $workspace, array $scopes, ?CategorySubtrees $subtrees): array
    {
        $facets = array_map(static fn (TransactionFacetScope $scope): TransactionFacet => $scope->facet, $scopes);
        $categories = in_array(TransactionFacet::CATEGORY, $facets, true);
        $axes = in_array(TransactionFacet::AXIS, $facets, true);

        $counts = [];
        if ($categories && null !== $subtrees) {
            $counts[TransactionFacet::CATEGORY->value] = $this->countRolledUp($workspace, $scopes[0]->filters, $subtrees);
        }
        if (($categories && null === $subtrees) || $axes) {
            $counts = [...$counts, ...$this->countSplitCombinations($workspace, $scopes[0]->filters, $categories && null === $subtrees, $axes)];
        }

        return $counts;
    }

    /**
     * One pass tallies the splits of the matching transactions by category and axis list
     * together (a few dozen combinations), whichever of the two are wanted.
     *
     * A category counts its splits: a transaction holds at most one split per category (the
     * splits' unique (transaction_id, category_id)), so the splits of a category are its
     * transactions, and one split across two categories counts in each.
     *
     * An axis counts transactions, and several splits of one transaction may carry it: the splits
     * of an axis list are spelled out axis by axis, and the splits whose axis an earlier split of
     * the same transaction already carries are taken back.
     *
     * @return array<string, array<string, int>>
     */
    private function countSplitCombinations(WorkspaceScope $workspace, TransactionFilters $filters, bool $categories, bool $axes): array
    {
        $tally = $this->reader->read(match (true) {
            $categories && $axes => FilteredRead::CATEGORY_AXES_TALLY,
            $categories => FilteredRead::CATEGORY_TALLY,
            default => FilteredRead::AXES_TALLY,
        }, $workspace, $filters);

        $byCategory = [];
        $byAxis = [];
        foreach ($tally as $combination) {
            $matches = (int) TransactionRow::text($combination['matches'] ?? null);
            if ($categories) {
                $category = TransactionRow::text($combination['category_id'] ?? null);
                $byCategory[$category] = ($byCategory[$category] ?? 0) + $matches;
            }
            if ($axes) {
                foreach (TransactionFacetTally::axisList(TransactionRow::text($combination['axes'] ?? null)) as $axis) {
                    $byAxis[$axis] = ($byAxis[$axis] ?? 0) + $matches;
                }
            }
        }

        $counts = [];
        if ($categories) {
            $counts[TransactionFacet::CATEGORY->value] = $byCategory;
        }
        if ($axes) {
            $counts[TransactionFacet::AXIS->value] = $this->withoutRepeats($workspace, $byAxis, $filters);
        }

        return $counts;
    }

    /**
     * How many splits of the matching transactions carry an axis that an earlier split of the same
     * transaction already carries, by axis. Only a split that is not the first of its transaction
     * can repeat an axis, so this looks at those alone.
     *
     * @return array<string, int>
     */
    private function countRepeatedAxes(WorkspaceScope $workspace, TransactionFilters $filters): array
    {
        return self::countsByValue($this->reader->read(
            FilteredRead::REPEATED_AXES,
            $workspace,
            $filters,
            ['facet_axes' => TransactionRow::postgresArray(array_map(static fn (AnalyticAxis $axis): string => $axis->value, AnalyticAxis::cases()))],
        ));
    }

    /**
     * Each category counts the transactions that have a split anywhere in its subtree, once.
     * Joining every split to its ancestors counts a transaction once per split of its that sits
     * below an ancestor; the statement takes back the splits for which an earlier split of the
     * same transaction already sits below that ancestor.
     *
     * @return array<string, int>
     */
    private function countRolledUp(WorkspaceScope $workspace, TransactionFilters $filters, CategorySubtrees $subtrees): array
    {
        [$ancestors, $descendants] = $subtrees->pairs();

        return self::countsByValue($this->reader->read(
            FilteredRead::ROLLED_UP,
            $workspace,
            $filters,
            ['ancestor_ids' => TransactionRow::postgresArray($ancestors), 'descendant_ids' => TransactionRow::postgresArray($descendants)],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private static function countsByValue(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[TransactionRow::text($row['facet_value'] ?? null)] = (int) TransactionRow::text($row['matches'] ?? null);
        }

        return $counts;
    }
}
