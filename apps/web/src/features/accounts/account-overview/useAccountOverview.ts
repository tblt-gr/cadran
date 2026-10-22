import { readAccountOverview } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';

/** Group headings with server-calculated totals; the rows are read per group. */
export function useAccountOverview(includeClosed: boolean, includeArchived: boolean) {
  return useQuery({
    queryKey: ['accounts', 'overview', includeClosed, includeArchived],
    queryFn: async ({ signal }) => {
      const result = await readAccountOverview({
        ...authApiOptions(),
        query: { includeArchived, includeClosed },
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
