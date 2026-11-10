import {
  listTransactions,
  type ListTransactionsData,
  type TransactionPage,
} from '@cadran/api-client';
import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import {
  TransactionRequestError,
  transactionRequestError,
} from '@/features/transactions/transactionError';

type Filters = NonNullable<ListTransactionsData['query']>;
type CachedPages = { pages: TransactionPage[]; pageParams: Array<string | undefined> };

/** Both transaction surfaces share the same bounded cursor contract and recovery semantics. */
export function useTransactionPages(filters: Filters, impossible: boolean, accountDetail = false) {
  const client = useQueryClient();
  const scope = JSON.stringify(filters);
  const [session, setSession] = useState({ scope, generation: 0 });
  const generation = session.scope === scope ? session.generation : session.generation + 1;
  if (session.scope !== scope) setSession({ scope, generation });
  const queryKey = accountDetail
    ? [
        'transactions',
        'account-detail',
        { ...filters, cursor: undefined, pageSize: 50 },
        generation,
      ]
    : ['transactions', { ...filters, cursor: undefined, pageSize: 50 }, generation];
  const [revision, setRevision] = useState(1);
  const inFlight = useRef<string | null>(null);
  const requestScope = `${scope}:${generation}`;
  const query = useInfiniteQuery({
    queryKey,
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ signal, pageParam }) => {
      const result = await listTransactions({
        ...authApiOptions(),
        query: { ...filters, cursor: pageParam, pageSize: 50 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    getNextPageParam: (page) => page.nextCursor ?? undefined,
    enabled: !impossible,
    retry: false,
    gcTime: 0,
    // A later cursor is bound to the first page's watermark. Refresh explicitly from the start.
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchOnMount: false,
  });
  const pages = query.data?.pages ?? [];
  const seen = new Set<string>();
  const items = pages
    .flatMap((page) => page.items)
    .filter((item) => {
      if (seen.has(item.id)) return false;
      seen.add(item.id);
      return true;
    });
  const displayedPage = query.data ? { items, nextCursor: pages.at(-1)?.nextCursor ?? null } : null;
  const staleCursorError =
    query.error instanceof TransactionRequestError && query.error.kind === 'staleCursor';
  const unauthorized =
    query.error instanceof TransactionRequestError && query.error.kind === 'unauthorized';

  function loadMore() {
    if (
      inFlight.current === requestScope ||
      query.isFetching ||
      !query.hasNextPage ||
      staleCursorError ||
      unauthorized
    )
      return;
    inFlight.current = requestScope;
    void query.fetchNextPage({ cancelRefetch: false }).finally(() => {
      if (inFlight.current === requestScope) inFlight.current = null;
    });
  }

  async function reloadFromFirstPage() {
    await client.cancelQueries({ queryKey, exact: true });
    client.setQueryData<CachedPages>(queryKey, (old) =>
      old
        ? {
            pages: old.pages.slice(0, 1),
            pageParams: old.pageParams.slice(0, 1),
          }
        : old,
    );
    setRevision((current) => current + 1);
    await client.refetchQueries({ queryKey, exact: true, type: 'active' });
  }

  async function reloadAfterWrite() {
    await client.cancelQueries({ queryKey: ['transactions'] });
    // Truncate all mounted cursor chains before invalidation, including the other surface.
    client.setQueriesData<CachedPages>({ queryKey: ['transactions'] }, (old) =>
      old && Array.isArray(old.pages)
        ? {
            ...old,
            pages: old.pages.slice(0, 1),
            pageParams: old.pageParams.slice(0, 1),
          }
        : old,
    );
    setRevision((current) => current + 1);
    await client.invalidateQueries({ queryKey: ['transactions'], refetchType: 'none' });
    await client.refetchQueries(
      { queryKey: ['transactions'], type: 'active' },
      { cancelRefetch: false },
    );
  }

  return {
    displayedPage,
    items,
    loadMore,
    loadSequence: revision + pages.length,
    reloadAfterWrite,
    reloadFromFirstPage,
    resetKey: `${JSON.stringify(queryKey)}:${revision}`,
    staleCursorError,
    unauthorized,
    transactions: { ...query, data: displayedPage ?? undefined, refetch: query.refetch },
  };
}
