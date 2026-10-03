import { previewPortfolioOperation, type PortfolioOperationRequest } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

const DEBOUNCE_MS = 400;

/** The server-derived cash effect of a valid request, queried 400 ms after the last edit. */
export function useOperationPreview(accountId: string, request: PortfolioOperationRequest | null) {
  const serialized = request ? JSON.stringify(request) : null;
  const [settled, setSettled] = useState(serialized);
  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(serialized), DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [serialized]);

  return useQuery({
    queryKey: ['portfolio-operation-preview', accountId, settled],
    enabled: settled !== null && settled === serialized,
    queryFn: async () => {
      const result = await withCsrfRetry(() =>
        previewPortfolioOperation({
          ...authApiOptions(),
          path: { accountId },
          body: JSON.parse(settled!) as PortfolioOperationRequest,
        }),
      );
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
    retry: false,
  });
}
