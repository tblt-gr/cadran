<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\CategorySubtrees;
use App\Module\Transactions\Domain\TransactionFacet;
use App\Module\Transactions\Domain\TransactionFacetScope;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;

/**
 * The number beside every option of the filter column: how many transactions the list would
 * return with that option checked alone in its section, every other filter applied.
 *
 * Each section is counted under all the filters but its own (so a checked option never hides
 * its siblings), and every filter goes through the same {@see ResolveTransactionFilters} as the
 * list, so no filter is read differently here than there.
 */
final readonly class ReadTransactionFacets
{
    /** Rolling counts up reads the whole category tree: beyond this many categories it is refused, not walked. */
    public const int MAX_CATEGORIES = 500;

    public function __construct(
        private CallerWorkspace $caller,
        private TransactionRepository $transactions,
        private ResolveTransactionFilters $resolveFilters,
        private AccountRepository $accounts,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * @throws WorkspaceAccessDenied
     * @throws InvalidTransactionInput
     * @throws TransactionFilterAccountNotFound
     * @throws TransactionFacetScopeTooLarge
     */
    public function __invoke(ListTransactionsQuery $query): TransactionFacets
    {
        $workspace = $this->caller->resolve();
        // Only the caller's own accounts are counted: a foreign identifier is refused here, with the
        // same answer as an unknown one, rather than silently matching nothing.
        $this->assertAccountsExist($workspace, $query->accountIds);

        // The tree is read and expanded once, so that none of the sections walks it again.
        $subtrees = $query->includeDescendants ? $this->subtrees($workspace) : null;
        $expanded = null === $subtrees ? $query : $query->with([
            'categoryIds' => $subtrees->expand($query->categoryIds),
            'includeDescendants' => false,
        ]);

        $scopes = [];
        foreach (TransactionFacet::cases() as $facet) {
            $filters = ($this->resolveFilters)($workspace, self::liftOwnFilter($facet, $expanded));
            // A null resolution is an empty state scope: no transaction can match, so there is nothing to ask.
            if (null !== $filters) {
                $scopes[] = new TransactionFacetScope($facet, $filters);
            }
        }

        // Asked for together, so that the sections counted under the same filters share their statements.
        return new TransactionFacets($this->transactions->facetCounts($workspace, $scopes, $subtrees));
    }

    /**
     * The request as the section of $facet sees it. Checking a category or an axis takes the
     * list out of the to-categorise queue, so those two sections do not apply it; the state
     * section counts each of the four states, as the list would with that state checked alone.
     */
    private static function liftOwnFilter(TransactionFacet $facet, ListTransactionsQuery $query): ListTransactionsQuery
    {
        return match ($facet) {
            TransactionFacet::ACCOUNT => $query->with(['accountIds' => []]),
            TransactionFacet::CATEGORY => $query->with(['categoryIds' => [], 'categorizationNone' => false]),
            TransactionFacet::NATURE => $query->with(['natures' => []]),
            TransactionFacet::STATE => $query->with(['states' => array_map(static fn (TransactionState $state): string => $state->value, TransactionState::cases())]),
            TransactionFacet::SOURCE => $query->with(['sources' => []]),
            TransactionFacet::AXIS => $query->with(['axes' => [], 'categorizationNone' => false]),
        };
    }

    /** @param list<string> $accountIds */
    private function assertAccountsExist(WorkspaceScope $workspace, array $accountIds): void
    {
        foreach (array_unique($accountIds) as $accountId) {
            if (null === $this->accounts->find($workspace, $accountId)) {
                throw new TransactionFilterAccountNotFound('A filtered account does not exist in this workspace.');
            }
        }
    }

    private function subtrees(WorkspaceScope $workspace): CategorySubtrees
    {
        $categories = $this->categories->list($workspace, true, self::MAX_CATEGORIES + 1, 0);
        if (count($categories) > self::MAX_CATEGORIES) {
            throw new TransactionFacetScopeTooLarge(sprintf('Rolling counts up reads at most %d categories.', self::MAX_CATEGORIES));
        }

        return CategorySubtrees::of($categories);
    }
}
