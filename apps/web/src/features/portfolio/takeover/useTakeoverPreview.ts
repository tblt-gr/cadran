import { previewPortfolioTakeover, type PortfolioTakeoverRequest } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

const DEBOUNCE_MS = 300;

/** The server-computed values, unit costs and errors of a complete request, 300 ms after the last edit. */
export function useTakeoverPreview(accountId: string, request: PortfolioTakeoverRequest | null) {
  const serialized = request ? JSON.stringify(request) : null;
  const [settled, setSettled] = useState(serialized);
  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(serialized), DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [serialized]);

  const result = useQuery({
    queryKey: ['portfolio-takeover-preview', accountId, settled],
    enabled: settled !== null && settled === serialized,
    queryFn: async () => {
      const response = await withCsrfRetry(() =>
        previewPortfolioTakeover({
          ...authApiOptions(),
          path: { accountId },
          body: JSON.parse(settled!) as PortfolioTakeoverRequest,
        }),
      );
      if (!response.response?.ok || !response.data) throw operationRequestError(response);
      return response.data;
    },
    retry: false,
  });
  const current = settled === serialized;
  return {
    ...result,
    data: current && !result.isFetching && !result.isError ? result.data : undefined,
    isError: current && result.isError,
    isFetching: (serialized !== null && !current) || result.isFetching,
  };
}
