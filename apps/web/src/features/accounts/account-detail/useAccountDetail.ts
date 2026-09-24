import { readAccount, type Account } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { authApiOptions } from '@/features/auth/apiOptions';

/**
 * The single account this page is about, closed or archived included: the
 * account stays linkable for historical reading after either. A missing
 * identifier and one from another workspace both answer the same 404, and a
 * workspace-less session answers 403; neither discloses whether the account
 * exists, so this hook classifies both through the same `accountError`
 * helper every other account request uses rather than branching on status
 * itself.
 */
export function useAccountDetail(accountId: string) {
  return useQuery<Account>({
    queryKey: ['account', accountId],
    queryFn: async ({ signal }) => {
      const result = await readAccount({
        ...authApiOptions(),
        path: { id: accountId },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data as Account;
    },
    retry: false,
  });
}
