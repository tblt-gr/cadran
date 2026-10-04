import { readPortfolioValuationHistory } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

export function useValueHistory(accountId: string, to: string, from: string | undefined) {
  return useQuery({
    queryKey: ['portfolio-valuation-history', accountId, from ?? null, to],
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
