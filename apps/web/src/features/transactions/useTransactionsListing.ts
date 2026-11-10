import { useQuery } from '@tanstack/react-query';
import { useTransactionPages } from './transaction-list/useTransactionPages';
import {
  isImpossibleCombination,
  toListTransactionsQuery,
} from './transaction-filters/filterState';
import { useTransactionFilters } from './transaction-filters/useTransactionFilters';
import {
  listActiveAccounts,
  listReferencedAccounts,
  mergeAccountOptions,
} from './transactionAccounts';

/**
 * Filters, cursor paging and the three queries the transactions screen reads from: the
 * active accounts, the filtered page of transactions, and the accounts a loaded page
 * references but which paging alone would not have fetched (an archived or closed one).
 * Isolated from the page's editors and mutations so each can be reasoned about on its own.
 */
export function useTransactionsListing() {
  const {
    filters: searchFilters,
    reset: resetFilters,
    revision: filtersRevision,
    setFilters,
  } = useTransactionFilters();
  const categorization = searchFilters.categorization === 'NONE' ? 'NONE' : 'ALL';

  function updateFilters(next: typeof searchFilters, mode?: 'push') {
    setFilters(next, mode);
  }

  function resetAllFilters() {
    resetFilters();
  }

  const impossible = isImpossibleCombination(searchFilters);
  const bulkQuery = toListTransactionsQuery(searchFilters, new Date());
  const accounts = useQuery({
    queryKey: ['accounts', { includeArchived: false, includeClosed: false, page: 1 }],
    queryFn: ({ signal }) => listActiveAccounts(signal),
    retry: false,
  });

  const listing = useTransactionPages(bulkQuery, impossible);
  const { items } = listing;
  const referencedAccountIds = [...new Set(items.map((transaction) => transaction.accountId))];
  const referencedAccounts = useQuery({
    queryKey: ['transaction-accounts', referencedAccountIds],
    queryFn: ({ signal }) => listReferencedAccounts(referencedAccountIds, signal),
    enabled: referencedAccountIds.length > 0,
    retry: false,
  });
  const activeAccountOptions = accounts.data?.items ?? [];
  const accountOptions = mergeAccountOptions(activeAccountOptions, referencedAccounts.data ?? []);
  return {
    ...listing,
    accountOptions,
    accountsPending: accounts.isPending,
    bulkQuery,
    activeAccountOptions,
    categorization,
    filtersRevision,
    impossible,
    items,
    resetAllFilters,
    searchFilters,
    updateFilters,
  };
}
