import {
  listPortfolios,
  readPortfolioSummary,
  readPortfolioAllocation,
  type PortfolioOverviewAllocation,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from './portfolio-account/operations/operationError';
export type AllocationBy = PortfolioOverviewAllocation['by'];
export function usePortfolios() {
  return useQuery({
    queryKey: ['portfolios'],
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await listPortfolios({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
  });
}
export function usePortfolioSummary(accountId: string, asOf: string, enabled = true) {
  return useQuery({
    queryKey: ['portfolio-summary', accountId, asOf],
    enabled,
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await readPortfolioSummary({
        ...authApiOptions(),
        path: { accountId },
        query: { asOf },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
  });
}
export function usePortfolioAllocation(
  accountId: string,
  asOf: string,
  by: AllocationBy,
  enabled = true,
) {
  return useQuery({
    queryKey: ['portfolio-allocation', accountId, asOf, by],
    enabled,
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await readPortfolioAllocation({
        ...authApiOptions(),
        path: { accountId },
        query: { asOf, by },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
  });
}
