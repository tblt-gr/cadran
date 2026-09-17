import { listAccounts, listTransactions, type TransactionPage } from '@cadran/api-client';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import type { Categorization } from './categorization-tabs/CategorizationTabs';
import {
  isImpossibleCombination,
  toListTransactionsQuery,
} from './transaction-filters/filterState';
import { useTransactionFilters } from './transaction-filters/useTransactionFilters';
import { TransactionRequestError, transactionRequestError } from './transactionError';
import { listReferencedAccounts, mergeAccountOptions } from './transactionAccounts';

/**
 * Filters, cursor paging and the three queries the transactions screen reads from: the
 * active accounts, the filtered page of transactions, and the accounts a loaded page
 * references but which paging alone would not have fetched (an archived or closed one).
 * Isolated from the page's editors and mutations so each can be reasoned about on its own.
 */
export function useTransactionsListing() {
  const queryClient = useQueryClient();
  const {
    filters: searchFilters,
    reset: resetFilters,
    revision: filtersRevision,
    setFilters,
  } = useTransactionFilters();
  const [cursor, setCursor] = useState<string | null>(null);
  // Paging replaces rather than appends rows, so two consecutive loads can carry the same
  // row count and produce an identical announced string. This monotonic counter changes on
  // every filter change and every page navigation, so the announcement always changes with
  // it and a screen reader re-reads it even when the count happens to stay the same.
  const [loadSequence, setLoadSequence] = useState(1);

  const categorization: Categorization = searchFilters.categorization === 'NONE' ? 'NONE' : 'ALL';

  function updateFilters(next: typeof searchFilters) {
    setCursor(null);
    setFilters(next);
    setLoadSequence((current) => current + 1);
  }

  function resetAllFilters() {
    setCursor(null);
    resetFilters();
    setLoadSequence((current) => current + 1);
  }

  const impossible = isImpossibleCombination(searchFilters);
  const filters = {
    ...toListTransactionsQuery(searchFilters, new Date()),
    cursor: cursor ?? undefined,
    pageSize: 50,
  };

  const accounts = useQuery({
    queryKey: ['accounts', { includeArchived: false, includeClosed: false, page: 1 }],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived: false, includeClosed: false, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    retry: false,
  });

  const transactions = useQuery({
    queryKey: ['transactions', filters],
    queryFn: async ({ signal }) => {
      const result = await listTransactions({
        ...authApiOptions(),
        query: filters,
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    enabled: !impossible,
    placeholderData: keepPreviousData,
    retry: false,
  });
  const staleCursorError =
    transactions.error instanceof TransactionRequestError &&
    transactions.error.kind === 'staleCursor';
  // The keyset cursor was invalidated by a concurrent change: the rows already on screen are
  // kept, with the footer offering to reload from the first page, instead of blanking the list.
  // Storing the last successful page in state (updated during render, not in an effect) is the
  // documented pattern for remembering information across renders without an extra commit; a
  // ref mutated during render would make this component impure and disable its compilation.
  const [lastGoodPage, setLastGoodPage] = useState<TransactionPage | null>(
    transactions.data ?? null,
  );
  if (transactions.data && transactions.data !== lastGoodPage) {
    setLastGoodPage(transactions.data);
  }
  const displayedPage = transactions.data ?? (staleCursorError ? lastGoodPage : null);
  const items = displayedPage?.items ?? [];
  const referencedAccountIds = [...new Set(items.map((transaction) => transaction.accountId))];
  const referencedAccounts = useQuery({
    queryKey: ['transaction-accounts', referencedAccountIds],
    queryFn: ({ signal }) => listReferencedAccounts(referencedAccountIds, signal),
    enabled: referencedAccountIds.length > 0,
    retry: false,
  });
  const activeAccountOptions = accounts.data?.items ?? [];
  const accountOptions = mergeAccountOptions(activeAccountOptions, referencedAccounts.data ?? []);
  const unauthorized =
    transactions.error instanceof TransactionRequestError &&
    transactions.error.kind === 'unauthorized';

  /**
   * Reloads the list from its first page after a write. Any write moves the workspace watermark,
   * so refetching a later page with its old cursor would only answer `cursor_stale` and leave
   * the pre-write rows on screen. Only first-page queries are refetched now; a later page being
   * displayed is marked stale and replaced by the first page once the cursor is cleared.
   */
  async function reloadAfterWrite() {
    if (cursor !== null) {
      setCursor(null);
      setLoadSequence((current) => current + 1);
    }
    await queryClient.invalidateQueries({ queryKey: ['transactions'], refetchType: 'none' });
    await queryClient.refetchQueries(
      {
        predicate: (query) =>
          query.queryKey[0] === 'transactions' &&
          !(query.queryKey[1] as { cursor?: string } | undefined)?.cursor,
        type: 'active',
      },
      { cancelRefetch: false },
    );
  }

  function loadMore() {
    setCursor(displayedPage?.nextCursor ?? null);
    setLoadSequence((current) => current + 1);
  }

  function reloadFromFirstPage() {
    setCursor(null);
    setLoadSequence((current) => current + 1);
  }

  return {
    accountOptions,
    activeAccountOptions,
    categorization,
    displayedPage,
    filtersRevision,
    impossible,
    items,
    loadMore,
    loadSequence,
    reloadAfterWrite,
    reloadFromFirstPage,
    resetAllFilters,
    searchFilters,
    staleCursorError,
    transactions,
    unauthorized,
    updateFilters,
  };
}
