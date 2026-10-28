import { readAccountOverview } from '@cadran/api-client';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';

/** Section headings with server-calculated totals; the rows are read per section. */
export function useAccountOverview(
  grouping: GroupingMode,
  includeClosed: boolean,
  includeArchived: boolean,
) {
  return useQuery({
    queryKey: ['accounts', 'overview', grouping, includeClosed, includeArchived],
    queryFn: async ({ signal }) => {
      const result = await readAccountOverview({
        ...authApiOptions(),
        query: { grouping, includeArchived, includeClosed },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data!;
    },
    retry: false,
  });
}
