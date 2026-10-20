import { readAccountRules, type AccountRules } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { authApiOptions } from '@/features/auth/apiOptions';

/**
 * The rules in force for one account on one business date.
 *
 * The date belongs to the cache key rather than to a filter applied afterwards:
 * the ceiling of June and the ceiling of December are two different answers, and
 * keeping them apart is what stops a revision from being shown for a period it
 * never covered.
 */
export function useAccountRules(accountId: string, asOf: string) {
  return useQuery<AccountRules>({
    queryKey: ['account-rules', accountId, asOf],
    queryFn: async ({ signal }) => {
      const result = await readAccountRules({
        ...authApiOptions(),
        path: { id: accountId },
        query: { asOf },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data as AccountRules;
    },
    retry: false,
  });
}
