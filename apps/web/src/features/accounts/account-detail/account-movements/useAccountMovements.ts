import { listTransactions, type TransactionPage } from '@cadran/api-client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import {
  isImpossibleCombination,
  toListTransactionsQuery,
  type TransactionFilterState,
} from '@/features/transactions/transaction-filters/filterState';
import {
  TransactionRequestError,
  transactionRequestError,
} from '@/features/transactions/transactionError';

const PAGE_SIZE = 50;

/**
 * Cursor-paged operations of one account under the shared transaction filter set. The account
 * is always the only one queried: no filter can widen the list beyond it.
 */
export function useAccountMovements(accountId: string, selection: TransactionFilterState) {
  const queryClient = useQueryClient();
  const [cursor, setCursor] = useState<string | null>(null);
  const scope = JSON.stringify([accountId, selection]);
  const [previousScope, setPreviousScope] = useState(scope);
  const scopeChanged = previousScope !== scope;
  if (scopeChanged) {
    setPreviousScope(scope);
    setCursor(null);
  }
  const impossible = isImpossibleCombination(selection);
  const bulkQuery = {
    ...toListTransactionsQuery({ ...selection, accountId: [] }, new Date()),
    accountId: [accountId],
  };

  const filters = {
    ...bulkQuery,
    cursor: scopeChanged ? undefined : (cursor ?? undefined),
    pageSize: PAGE_SIZE,
  };

  const transactions = useQuery({
    queryKey: ['transactions', 'account-detail', filters],
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
    // Keeps the rows of the same filters while a later page loads; a new filter set starts
    // from its own skeleton rather than showing rows it does not match.
    placeholderData: (previousData, previousQuery) => {
      const previousFilters = previousQuery?.queryKey[2] as typeof filters | undefined;
      return JSON.stringify({ ...previousFilters, cursor: undefined }) ===
        JSON.stringify({ ...filters, cursor: undefined })
        ? previousData
        : undefined;
    },
    retry: false,
  });

  const staleCursorError =
    transactions.error instanceof TransactionRequestError &&
    transactions.error.kind === 'staleCursor';
  const [lastGood, setLastGood] = useState<{ scope: string; page: TransactionPage } | null>(null);
  if (
    transactions.data &&
    !transactions.isPlaceholderData &&
    (lastGood?.page !== transactions.data || lastGood.scope !== scope)
  ) {
    setLastGood({ scope, page: transactions.data });
  }
  const displayedPage = scopeChanged
    ? null
    : (transactions.data ??
      ((cursor !== null || staleCursorError) && lastGood?.scope === scope ? lastGood.page : null));

  const items = displayedPage?.items ?? [];
  const unauthorized =
    transactions.error instanceof TransactionRequestError &&
    transactions.error.kind === 'unauthorized';

  async function reloadAfterWrite() {
    if (cursor !== null) {
      setCursor(null);
    }
    await queryClient.invalidateQueries({
      queryKey: ['transactions'],
      refetchType: 'none',
    });
    await queryClient.refetchQueries(
      {
        predicate: (query) =>
          query.queryKey[0] === 'transactions' &&
          query.queryKey[1] === 'account-detail' &&
          !(query.queryKey[2] as { cursor?: string } | undefined)?.cursor,
        type: 'active',
      },
      { cancelRefetch: false },
    );
  }

  function loadMore() {
    setCursor(displayedPage?.nextCursor ?? null);
  }

  function reloadFromFirstPage() {
    setCursor(null);
  }

  return {
    bulkQuery,
    displayedPage,
    impossible,
    items,
    loadMore,
    reloadAfterWrite,
    reloadFromFirstPage,
    staleCursorError,
    transactions,
    unauthorized,
  };
}
