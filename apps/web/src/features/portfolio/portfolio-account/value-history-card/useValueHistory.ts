import { readPortfolioValuationHistory } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

type History = NonNullable<Awaited<ReturnType<typeof readPortfolioValuationHistory>>['data']>;

export function useValueHistory(accountId: string, to: string, from: string | undefined) {
  return useQuery({
    queryKey: ['portfolio-valuation-history', accountId, from ?? null, to],
    // A new range keeps the previous curve of the same account on screen while it loads.
    placeholderData: (previous: History | undefined, previousQuery) =>
      previousQuery?.queryKey[1] === accountId ? previous : undefined,
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await readPortfolioValuationHistory({
        ...authApiOptions(),
        path: { accountId },
        query: from === undefined ? { to } : { from, to },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
  });
}
