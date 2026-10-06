import { previewPortfolioOperation, type PortfolioOperationRequest } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

const DEBOUNCE_MS = 400;

/** The server-derived cash effect of a valid request, queried 400 ms after the last edit. */
export function useOperationPreview(
  accountId: string,
  request: PortfolioOperationRequest | null,
  correctingOperationId?: string,
) {
  const serialized = request ? JSON.stringify({ request, correctingOperationId }) : null;
  const [settled, setSettled] = useState(serialized);
  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(serialized), DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [serialized]);

  const result = useQuery({
    queryKey: ['portfolio-operation-preview', accountId, correctingOperationId ?? null, settled],
    enabled: settled !== null && settled === serialized,
    queryFn: async () => {
      const input = JSON.parse(settled!) as {
        request: PortfolioOperationRequest;
        correctingOperationId?: string;
      };
      const result = await withCsrfRetry(() =>
        previewPortfolioOperation({
          ...authApiOptions(),
          path: { accountId },
          body: input.request,
          ...(input.correctingOperationId
            ? { query: { correctingOperationId: input.correctingOperationId } }
            : {}),
        }),
      );
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
    retry: false,
  });
  return {
    ...result,
    data: settled === serialized ? result.data : undefined,
    error: settled === serialized ? result.error : null,
    isFetching: (serialized !== null && settled !== serialized) || result.isFetching,
  };
}
