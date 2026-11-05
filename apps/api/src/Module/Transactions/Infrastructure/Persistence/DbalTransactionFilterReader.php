<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionPosition;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Every statement that reads the transactions a {@see TransactionFilters} narrows: a page of the
 * listing, its count and the tallies behind the facet counts of the filter column. They are all
 * built in {@see read()}, from one assembly of the filter clauses, so no filter is read
 * differently by one of them. What the tallies mean is the business of
 * {@see DbalTransactionFacetReader}, which holds no SQL.
 */
final readonly class DbalTransactionFilterReader
{
    // The three filters that look into the splits. Each fragment carries its own bound
    // :workspace_id predicate rather than comparing s.workspace_id to t.workspace_id: an EXISTS
    // subquery must never let the outer, already-scoped table vouch for the inner one. They are
    // constants appended in one unconditional concatenation (never each behind its own
    // `if ($sql .= ...)`), and every statement lives in the one method that executes it, so the
    // static workspace-scope guard (scripts/check-workspace-scope.php) can resolve the full query
    // text: a scoped table named in a method that executes nothing, or only inside a
    // conditionally-appended statement, is invisible to its analysis. Their :category_ids and
    // :axes are bound by TransactionFilterPredicates.
    private const string UNSPLIT = ' AND NOT EXISTS (SELECT 1 FROM transaction_splits s WHERE s.workspace_id = :workspace_id AND s.transaction_id = t.id)';
    private const string IN_CATEGORIES = ' AND EXISTS (SELECT 1 FROM transaction_splits s WHERE s.workspace_id = :workspace_id AND s.transaction_id = t.id AND s.category_id IN (:category_ids))';
    // Membership is checked on the JSON array directly, without expanding every split into
    // one row per axis on each facet scan. Any requested axis is enough, as in the listing.
    private const string ON_AXES = ' AND EXISTS (SELECT 1 FROM transaction_splits s WHERE s.workspace_id = :workspace_id AND s.transaction_id = t.id AND jsonb_exists_any(s.analytic_axes, CAST(:axes AS text[])))';

    /** @param string $columns what {@see rows()} reads of each transaction, a constant of the caller and never request data */
    public function __construct(private Connection $connection, private string $columns)
    {
    }

    /**
     * One page of the matching rows, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(WorkspaceScope $workspace, TransactionFilters $filters, int $limit, ?TransactionPosition $after): array
    {
        $bindings = ['limit' => $limit];
        if (null !== $after) {
            $bindings = [...$bindings, 'booked_on' => $after->bookedOn->format('Y-m-d'), 'cursor_id' => $after->id];
        }

        return $this->read(null === $after ? FilteredRead::ROWS : FilteredRead::ROWS_AFTER, $workspace, $filters, $bindings, ['limit' => ParameterType::INTEGER]);
    }

    /** The exact number of matching rows, whatever the page. */
    public function count(WorkspaceScope $workspace, TransactionFilters $filters): int
    {
        return (int) TransactionRow::text($this->read(FilteredRead::COUNT, $workspace, $filters)[0]['matches'] ?? null);
    }

    /**
     * @param array<string, mixed>                            $bindings values the statement binds besides the filters' own
     * @param array<string, ArrayParameterType|ParameterType> $types    the types of those that are not strings
     *
     * @return list<array<string, mixed>>
     */
    public function read(FilteredRead $read, WorkspaceScope $workspace, TransactionFilters $filters, array $bindings = [], array $types = []): array
    {
        $predicates = TransactionFilterPredicates::of($filters);
        $clauses = ($filters->categorizationNone ? self::UNSPLIT : '')
            .([] !== $filters->categoryIds ? self::IN_CATEGORIES : '')
            .([] !== $filters->axes ? self::ON_AXES : '')
            .$predicates->sql;
        $sql = match ($read) {
            FilteredRead::ROWS => 'SELECT '.$this->columns.' FROM transaction_transactions t WHERE t.workspace_id = :workspace_id'
                .$clauses
                .' ORDER BY t.booked_on DESC, t.id DESC LIMIT :limit',
            FilteredRead::ROWS_AFTER => 'SELECT '.$this->columns.' FROM transaction_transactions t WHERE t.workspace_id = :workspace_id'
                .$clauses
                .' AND (t.booked_on, t.id) < (:booked_on, :cursor_id) ORDER BY t.booked_on DESC, t.id DESC LIMIT :limit',
            FilteredRead::COUNT => 'SELECT count(*) AS matches FROM transaction_transactions t WHERE t.workspace_id = :workspace_id'
                .$clauses,
            FilteredRead::COLUMN_TALLY => 'SELECT t.account_id AS account_id, t.nature AS nature, t.source AS source, t.state AS state, count(*) AS matches'
                .' FROM transaction_transactions t WHERE t.workspace_id = :workspace_id'
                .$clauses
                .' GROUP BY t.account_id, t.nature, t.source, t.state',
            FilteredRead::COLUMN_CATEGORY_TALLY => 'SELECT t.account_id AS account_id, t.nature AS nature, t.source AS source, t.state AS state, fs.category_id AS category_id,'
                .' count(*) AS split_matches, count(*) FILTER (WHERE fs.position = 0 OR fs.id IS NULL) AS matches'
                .' FROM transaction_transactions t LEFT JOIN transaction_splits fs ON fs.workspace_id = :workspace_id AND fs.transaction_id = t.id'
                .' WHERE t.workspace_id = :workspace_id'
                .$clauses
                .' GROUP BY t.account_id, t.nature, t.source, t.state, fs.category_id',
            FilteredRead::CATEGORY_TALLY => 'SELECT fs.category_id AS category_id, count(*) AS matches FROM transaction_transactions t'
                .' JOIN transaction_splits fs ON fs.workspace_id = :workspace_id AND fs.transaction_id = t.id'
                .' WHERE t.workspace_id = :workspace_id'
                .$clauses
                .' GROUP BY fs.category_id',
            FilteredRead::AXES_TALLY => 'SELECT CAST(fs.analytic_axes AS text) AS axes, count(*) AS matches FROM transaction_transactions t'
                .' JOIN transaction_splits fs ON fs.workspace_id = :workspace_id AND fs.transaction_id = t.id'
                .' WHERE t.workspace_id = :workspace_id'
                .$clauses
                .' GROUP BY CAST(fs.analytic_axes AS text)',
            FilteredRead::CATEGORY_AXES_TALLY => 'SELECT fs.category_id AS category_id, CAST(fs.analytic_axes AS text) AS axes, count(*) AS matches FROM transaction_transactions t'
                .' JOIN transaction_splits fs ON fs.workspace_id = :workspace_id AND fs.transaction_id = t.id'
                .' WHERE t.workspace_id = :workspace_id'
                .$clauses
                .' GROUP BY fs.category_id, CAST(fs.analytic_axes AS text)',
            FilteredRead::REPEATED_AXES => 'SELECT ax.value AS facet_value, count(DISTINCT fs.id) AS matches FROM transaction_splits fs'
                .' JOIN transaction_splits earlier ON earlier.workspace_id = :workspace_id AND earlier.transaction_id = fs.transaction_id AND earlier.position < fs.position'
                .' JOIN unnest(CAST(:facet_axes AS text[])) AS ax(value) ON jsonb_exists(fs.analytic_axes, ax.value) AND jsonb_exists(earlier.analytic_axes, ax.value)'
                .' JOIN transaction_transactions t ON t.id = fs.transaction_id'
                .' WHERE fs.workspace_id = :workspace_id AND fs.position > 0 AND t.workspace_id = :workspace_id'
                .$clauses
                .' GROUP BY ax.value',
            FilteredRead::ROLLED_UP => 'SELECT counted.ancestor_id AS facet_value, sum(counted.delta) AS matches FROM ('
                .'SELECT p.ancestor_id, count(*) AS delta FROM transaction_transactions t'
                .' JOIN transaction_splits fs ON fs.workspace_id = :workspace_id AND fs.transaction_id = t.id'
                .' JOIN unnest(CAST(:ancestor_ids AS uuid[]), CAST(:descendant_ids AS uuid[])) AS p(ancestor_id, descendant_id) ON p.descendant_id = fs.category_id'
                .' WHERE t.workspace_id = :workspace_id'
                .$clauses
                .' GROUP BY p.ancestor_id'
                .' UNION ALL'
                .' SELECT p.ancestor_id, -count(DISTINCT fs.id) FROM transaction_splits fs'
                .' JOIN transaction_splits earlier ON earlier.workspace_id = :workspace_id AND earlier.transaction_id = fs.transaction_id AND earlier.position < fs.position'
                .' JOIN unnest(CAST(:ancestor_ids AS uuid[]), CAST(:descendant_ids AS uuid[])) AS p(ancestor_id, descendant_id) ON p.descendant_id = fs.category_id'
                .' JOIN unnest(CAST(:ancestor_ids AS uuid[]), CAST(:descendant_ids AS uuid[])) AS q(ancestor_id, descendant_id) ON q.descendant_id = earlier.category_id AND q.ancestor_id = p.ancestor_id'
                .' JOIN transaction_transactions t ON t.id = fs.transaction_id'
                .' WHERE fs.workspace_id = :workspace_id AND fs.position > 0 AND t.workspace_id = :workspace_id'
                .$clauses
                .' GROUP BY p.ancestor_id'
                .') counted GROUP BY counted.ancestor_id',
        };

        return $this->connection->fetchAllAssociative(
            $sql,
            ['workspace_id' => $workspace->id, ...$predicates->parameters, ...$bindings],
            [...$predicates->types, ...$types],
        );
    }
}
