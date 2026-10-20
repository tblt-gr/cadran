import { readAccountValuationHistory } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';

export function useSavingsHistory(accountId: string, from: string, to: string) {
  return useQuery({
    queryKey: ['account-valuation-history', accountId, from, to],
    queryFn: async ({ signal }) => {
      const result = await readAccountValuationHistory({
        ...authApiOptions(),
        path: { id: accountId },
        query: { from, to },
        signal,
      });
      if (requestFailed(result)) throw accountRequestError(result);
      return result.data!;
    },
    retry: false,
  });
}
