import { readPortfolioAnnualPerformance } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

/** The twelve months, the year and the inception line of one account; every operation and valuation change invalidates it. */
export function useAnnualPerformance(accountId: string, year: number) {
  return useQuery({
    queryKey: ['portfolio-performance-annual', accountId, year],
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await readPortfolioAnnualPerformance({
        ...authApiOptions(),
        path: { accountId },
        query: { year: String(year) },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
  });
}
