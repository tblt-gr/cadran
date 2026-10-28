import { readAccountOverviewRows } from '@cadran/api-client';
import { useInfiniteQuery } from '@tanstack/react-query';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { authApiOptions } from '@/features/auth/apiOptions';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';

interface GroupRowsScope {
  asOf: string;
  enabled: boolean;
  groupKey: string;
  grouping: GroupingMode;
  includeArchived: boolean;
  includeClosed: boolean;
}

/**
 * Members of one table, fifty at a time. The cursor is bound to the group, the
 * grouping mode and the order version: a stale one answers 409 and the page offers a reload.
 */
export function useGroupRows({
  asOf,
  enabled,
  groupKey,
  grouping,
  includeArchived,
  includeClosed,
}: GroupRowsScope) {
  return useInfiniteQuery({
    enabled,
    queryKey: [
      'accounts',
      'overview-rows',
      groupKey,
      grouping,
      asOf,
      includeClosed,
      includeArchived,
    ],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam, signal }) => {
      const result = await readAccountOverviewRows({
        ...authApiOptions(),
        path: { groupKey },
        query: { asOf, cursor: pageParam, grouping, includeArchived, includeClosed },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data!;
    },
    getNextPageParam: (last) => last.nextCursor ?? undefined,
    retry: false,
  });
}
