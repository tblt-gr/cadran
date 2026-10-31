import { monthBounds, type AccountMovementFilters } from './accountMovementFilters';
import { listTransactions, type TransactionPage } from '@cadran/api-client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import {
  TransactionRequestError,
  transactionRequestError,
} from '@/features/transactions/transactionError';

const PAGE_SIZE = 50;

/** Cursor-paged operations: period, type and state filters never replace the account boundary. */
export function useAccountMovements(
  accountId: string,
  initialMonth: string | null,
  selection?: AccountMovementFilters,
) {
  const queryClient = useQueryClient();
  const [cursor, setCursor] = useState<string | null>(null);
  const scope = JSON.stringify([accountId, initialMonth, selection]);
  const [previousScope, setPreviousScope] = useState(scope);
  const scopeChanged = previousScope !== scope;
  if (scopeChanged) {
    setPreviousScope(scope);
    setCursor(null);
  }
  const bounds = selection ?? monthBounds(initialMonth);
  const from = bounds.from || undefined;
  const to = bounds.to || undefined;
  const includeVoided = selection?.includeVoided || undefined;
  const nature = selection?.nature.length ? selection.nature : undefined;

  const filters = {
    accountId: [accountId],
    from,
    to,
    includeVoided,
    nature,
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
    placeholderData: (previousData, previousQuery) => {
      const previousFilters = previousQuery?.queryKey[2] as typeof filters | undefined;
      return previousFilters?.accountId[0] === accountId &&
        previousFilters.from === from &&
        previousFilters.to === to &&
        previousFilters.includeVoided === includeVoided &&
        JSON.stringify(previousFilters.nature) === JSON.stringify(nature)
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
    bulkQuery: { accountId: [accountId], from, to, includeVoided, nature },
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
