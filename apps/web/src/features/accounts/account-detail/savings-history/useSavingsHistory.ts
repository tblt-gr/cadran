import { readAccountValuationHistory } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';

type History = NonNullable<Awaited<ReturnType<typeof readAccountValuationHistory>>['data']>;

export function useSavingsHistory(accountId: string, from: string | undefined, to: string) {
  return useQuery({
    queryKey: ['account-valuation-history', accountId, from ?? 'all', to],
    queryFn: async ({ signal }) => {
      const result = await readAccountValuationHistory({
        ...authApiOptions(),
        path: { id: accountId },
        query: from === undefined ? { to } : { from, to },
        signal,
      });
      if (requestFailed(result)) throw accountRequestError(result);
      return result.data!;
    },
    // A new range keeps the previous curve of the same account on screen while it loads.
    placeholderData: (previous: History | undefined, previousQuery) =>
      previousQuery?.queryKey[1] === accountId ? previous : undefined,
    retry: false,
  });
}
