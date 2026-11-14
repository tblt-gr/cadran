import { readAccountOverviewRows, type AccountOverviewGroup } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { authApiOptions } from '@/features/auth/apiOptions';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';

interface AllRowsScope {
  asOf: string;
  groups: readonly AccountOverviewGroup[];
  grouping: GroupingMode;
  includeArchived: boolean;
  includeClosed: boolean;
}

/** Every member of every group, in the order of the groups: the accounts without any grouping. */
export function useAllRows({
  asOf,
  groups,
  grouping,
  includeArchived,
  includeClosed,
}: AllRowsScope) {
  const keys = groups.map((group) => group.groupId ?? 'ungrouped');
  return useQuery({
    queryKey: [
      'accounts',
      'overview-rows-all',
      keys,
      grouping,
      asOf,
      includeClosed,
      includeArchived,
    ],
    queryFn: async ({ signal }) => {
      const rows = [];
      for (const groupKey of keys) {
        let cursor: string | undefined;
        do {
          const result = await readAccountOverviewRows({
            ...authApiOptions(),
            path: { groupKey },
            query: { asOf, cursor, grouping, includeArchived, includeClosed },
            signal,
          });
          if (requestFailed(result)) throw accountRequestError(result);
          rows.push(...result.data!.items);
          cursor = result.data!.nextCursor ?? undefined;
        } while (cursor !== undefined);
      }
      return rows;
    },
    retry: false,
  });
}
