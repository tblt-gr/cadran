import {
  listPortfolioValuations,
  readPortfolioValuation,
  readPortfolioValuationDraft,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { valuationRequestError } from './valuationError';

export const VALUATIONS_PER_PAGE = 24;

export function useValuations(accountId: string, page: number, asOf?: string) {
  return useQuery({
    queryKey: ['portfolio-valuations', accountId, page, asOf],
    queryFn: async ({ signal }) => {
      const result = await listPortfolioValuations({
        ...authApiOptions(),
        path: { accountId },
        query: { page, pageSize: VALUATIONS_PER_PAGE, to: asOf },
        signal,
      });
      if (!result.response?.ok || !result.data) throw valuationRequestError(result);
      return result.data;
    },
    retry: false,
  });
}

export function useValuation(id: string | null) {
  return useQuery({
    queryKey: ['portfolio-valuation', id],
    enabled: id !== null,
    queryFn: async ({ signal }) => {
      const result = await readPortfolioValuation({
        ...authApiOptions(),
        path: { id: id! },
        signal,
      });
      if (!result.response?.ok || !result.data) throw valuationRequestError(result);
      return result.data;
    },
    retry: false,
  });
}

/** The server-built form for one date; disabled until the date is confirmed. */
export function useValuationDraft(accountId: string, valuedOn: string | null) {
  return useQuery({
    queryKey: ['portfolio-valuation-draft', accountId, valuedOn],
    enabled: valuedOn !== null,
    gcTime: 0,
    staleTime: Infinity,
    refetchOnWindowFocus: false,
    queryFn: async ({ signal }) => {
      const result = await readPortfolioValuationDraft({
        ...authApiOptions(),
        path: { accountId },
        query: { valuedOn: valuedOn! },
        signal,
      });
      if (!result.response?.ok || !result.data) throw valuationRequestError(result);
      return result.data;
    },
    retry: false,
  });
}
