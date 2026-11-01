import { readAccountOverviewComposition } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { authApiOptions } from '@/features/auth/apiOptions';

/**
 * Server-calculated split of the whole net worth by account, read at the overview's valuation
 * date. The key starts with `accounts`, so every write that refreshes the overview refreshes it.
 */
export function useNetWorthComposition(asOf: string) {
  return useQuery({
    queryKey: ['accounts', 'overview', 'composition', asOf],
    queryFn: async ({ signal }) => {
      const result = await readAccountOverviewComposition({
        ...authApiOptions(),
        query: { asOf },
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
