import { readPortfolioPerformance } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

export type PerformanceScope = 'MONTH' | 'YEAR' | 'INCEPTION';

/** Gain and returns of one account over a period; every operation and valuation change invalidates it. */
export function usePortfolioPerformance(
  accountId: string,
  scope: PerformanceScope,
  period: string | undefined,
  enabled = true,
) {
  return useQuery({
    queryKey: ['portfolio-performance', accountId, scope, period],
    enabled,
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await readPortfolioPerformance({
        ...authApiOptions(),
        path: { accountId },
        query: period === undefined ? { scope } : { scope, period },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
  });
}
