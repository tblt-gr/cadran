import { listTransactions, type TransactionPage } from '@cadran/api-client';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import {
  TransactionRequestError,
  transactionRequestError,
} from '@/features/transactions/transactionError';

const PAGE_SIZE = 50;

/** Inclusive `[from, to]` bound for one calendar month, or both null when unset. */
function monthBounds(month: string | null): { from?: string; to?: string } {
  if (month === null || !/^\d{4}-\d{2}$/.test(month)) {
    return {};
  }

  const [year, monthNumber] = month.split('-').map(Number) as [number, number];
  const lastDay = new Date(year, monthNumber, 0).getDate();

  return {
    from: `${month}-01`,
    to: `${month}-${String(lastDay).padStart(2, '0')}`,
  };
}

/**
 * This account's own movements, newest first, cursor-paged and locked to the
 * account the page is about. Unlike the workspace-wide transactions screen,
 * there is no filter UI here that could widen the query: the only optional
 * input is the initial period a Budget link may have asked for, and even
 * that never replaces or removes the account boundary.
 */
export function useAccountMovements(accountId: string, initialMonth: string | null) {
  const queryClient = useQueryClient();
  const [cursor, setCursor] = useState<string | null>(null);
  const { from, to } = monthBounds(initialMonth);

  const filters = {
    accountId: [accountId],
    from,
    to,
    cursor: cursor ?? undefined,
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
    placeholderData: keepPreviousData,
    retry: false,
  });

  const staleCursorError =
    transactions.error instanceof TransactionRequestError &&
    transactions.error.kind === 'staleCursor';
  const [lastGoodPage, setLastGoodPage] = useState<TransactionPage | null>(
    transactions.data ?? null,
  );
  if (transactions.data && transactions.data !== lastGoodPage) {
    setLastGoodPage(transactions.data);
  }
  const displayedPage = transactions.data ?? (staleCursorError ? lastGoodPage : null);
  const items = displayedPage?.items ?? [];
  const unauthorized =
    transactions.error instanceof TransactionRequestError &&
    transactions.error.kind === 'unauthorized';

  async function reloadAfterWrite() {
    if (cursor !== null) {
      setCursor(null);
    }
    await queryClient.invalidateQueries({
      queryKey: ['transactions', 'account-detail'],
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
    displayedPage,
    items,
    loadMore,
    reloadAfterWrite,
    reloadFromFirstPage,
    staleCursorError,
    transactions,
    unauthorized,
  };
}
