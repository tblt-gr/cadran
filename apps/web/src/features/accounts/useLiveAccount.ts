import type { Account } from '@cadran/api-client';
import { useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { useEffect, useReducer } from 'react';

interface RowsPage {
  items: Account[];
}

/**
 * The freshest copy of an account held by the overview tables. A modal opened
 * on a row must present the current version, or every retry would fail on a
 * stale write.
 */
export function useLiveAccount(account: Account | null): Account | null {
  const queryClient = useQueryClient();
  const [, refresh] = useReducer((count: number) => count + 1, 0);

  useEffect(() => {
    if (account === null) {
      return undefined;
    }
    // Only a data update of an overview rows query can change the row. Reacting to every cache
    // event (observer added, options updated) re-renders the page, whose query hooks then emit
    // those events again: a feedback loop that never settles while a modal is open.
    return queryClient.getQueryCache().subscribe((event) => {
      if (
        event.type === 'updated' &&
        event.query.queryKey[0] === 'accounts' &&
        event.query.queryKey[1] === 'overview-rows'
      ) {
        refresh();
      }
    });
  }, [account, queryClient]);

  if (account === null) {
    return null;
  }

  for (const [, data] of queryClient.getQueriesData<InfiniteData<RowsPage>>({
    queryKey: ['accounts', 'overview-rows'],
  })) {
    for (const page of data?.pages ?? []) {
      const live = page.items.find((item) => item.id === account.id);
      if (live) {
        return live;
      }
    }
  }

  return account;
}
