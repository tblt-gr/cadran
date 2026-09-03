import { readAccountRules, type AccountRules } from '@cadran/api-client';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { authApiOptions } from '@/features/auth/apiOptions';

/**
 * The rules in force for one account on one business date.
 *
 * The date belongs to the cache key rather than to a filter applied afterwards:
 * the ceiling of June and the ceiling of December are two different answers, and
 * keeping them apart is what stops a revision from being shown for a period it
 * never covered.
 *
 * Every date is therefore a cold key, and editing one date field segment at a
 * time walks through several of them. The previous answer stays on screen while
 * the next one is resolved, so the table is not torn down and rebuilt at each
 * keystroke; the caller marks it as the answer of the earlier date rather than
 * passing it off as the one being asked for.
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
    placeholderData: keepPreviousData,
    retry: false,
  });
}
