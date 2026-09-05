import { listAccountRuleOverrides, type AccountRuleOverridePage } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { authApiOptions } from '@/features/auth/apiOptions';

/**
 * Every claim ever recorded on one account, withdrawn ones included.
 *
 * It is a different question from the account's rules, which answer for one
 * business date. This answers what was ever claimed, by whom and why, and is
 * the only place a withdrawn claim remains visible — the trail that explains
 * why a past statement diverged from the published figures.
 */
export function useAccountRuleOverrides(accountId: string) {
  return useQuery<AccountRuleOverridePage>({
    queryKey: ['account-rule-overrides', accountId],
    queryFn: async ({ signal }) => {
      const result = await listAccountRuleOverrides({
        ...authApiOptions(),
        path: { id: accountId },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data as AccountRuleOverridePage;
    },
    retry: false,
  });
}
