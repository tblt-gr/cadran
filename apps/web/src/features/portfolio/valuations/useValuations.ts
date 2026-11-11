import {
  listPortfolioValuations,
  readPortfolioValuation,
  readPortfolioValuationDraft,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useIncrementalList } from '@/hooks/incremental-list/useIncrementalList';
import { authApiOptions } from '@/features/auth/apiOptions';
import { valuationRequestError } from './valuationError';

const VALUATIONS_PER_PAGE = 24;

export function useValuations(accountId: string, asOf?: string) {
  return useIncrementalList({
    queryKey: ['portfolio-valuations', accountId, asOf],
    request: async ({ signal, page, revision }) => {
      const result = await listPortfolioValuations({
        ...authApiOptions(),
        headers: { 'X-List-Revision': revision },
        path: { accountId },
        query: { page, pageSize: VALUATIONS_PER_PAGE, to: asOf },
        signal,
      });
      return result;
    },
    requestError: valuationRequestError,
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
