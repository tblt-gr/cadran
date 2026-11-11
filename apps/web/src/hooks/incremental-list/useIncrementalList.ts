import { useInfiniteQuery, type QueryKey } from '@tanstack/react-query';
import { useRef, useState } from 'react';

interface NumberedPage<T> {
  items: T[];
  page: number;
  pageSize: number;
  total: number;
}
export interface ListResponse<T> {
  data?: NumberedPage<T>;
  response?: Response;
  error?: unknown;
}
export interface ListPageRequest {
  page: number;
  revision: string;
  signal: AbortSignal;
}
class StaleList extends Error {}

/** Numbered pages retain their API contract; the opaque revision guards the entire chain. */
export function useIncrementalList<T>({
  queryKey,
  request,
  requestError,
}: {
  queryKey: QueryKey;
  request: (input: ListPageRequest) => Promise<ListResponse<T>>;
  requestError: (result: { response?: Response; error?: unknown }) => Error;
}) {
  const scope = JSON.stringify(queryKey);
  const [session, setSession] = useState({ scope, generation: 0 });
  const generation = session.scope === scope ? session.generation : session.generation + 1;
  if (session.scope !== scope) setSession({ scope, generation });
  const lock = useRef<string | null>(null);
  const identity = `${scope}:${generation}`;
  const query = useInfiniteQuery({
    queryKey: [...queryKey, 'incremental', generation],
    initialPageParam: { page: 1, revision: 'start' },
    queryFn: async ({ pageParam, signal }) => {
      if (pageParam.page > 1 && !pageParam.revision) throw new StaleList();
      const result = await request({ ...pageParam, signal });
      if (
        result.response?.status === 409 &&
        typeof result.error === 'object' &&
        result.error !== null &&
        'type' in result.error &&
        String(result.error.type).endsWith('/lists.revision_stale')
      )
        throw new StaleList();
      if (!result.response?.ok || !result.data) throw requestError(result);
      return { ...result.data, revision: result.response.headers?.get('X-List-Revision') ?? null };
    },
    getNextPageParam: (last) =>
      last.page * last.pageSize < last.total
        ? { page: last.page + 1, revision: last.revision ?? '' }
        : undefined,
    retry: false,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchOnMount: false,
  });
  const pages = query.data?.pages;
  const data = pages?.length
    ? { ...pages[0]!, items: pages.flatMap((page) => page.items) }
    : undefined;
  const stale = query.error instanceof StaleList;
  function loadMore() {
    if (lock.current === identity || query.isFetching || !query.hasNextPage || stale) return;
    lock.current = identity;
    void query.fetchNextPage({ cancelRefetch: false }).finally(() => {
      if (lock.current === identity) lock.current = null;
    });
  }
  function restart() {
    setSession({ scope, generation: generation + 1 });
  }
  return { ...query, data, loadMore, stale, restart, refetch: restart, resetKey: identity };
}
