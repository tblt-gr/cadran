import { listPortfolioOperations, type PortfolioOperationType } from '@cadran/api-client';
import { useIncrementalList } from '@/hooks/incremental-list/useIncrementalList';
import { authApiOptions } from '@/features/auth/apiOptions';
import type { OperationFilterValues } from './operation-filters/operationFilterValues';
import { operationRequestError } from './operationError';

const OPERATIONS_PER_PAGE = 50;

export function useOperations(accountId: string, filters: OperationFilterValues) {
  return useIncrementalList({
    queryKey: ['portfolio-operations', accountId, filters],
    request: async ({ signal, page, revision }) => {
      const result = await listPortfolioOperations({
        ...authApiOptions(),
        headers: { 'X-List-Revision': revision },
        path: { accountId },
        query: {
          from: filters.from || undefined,
          to: filters.to || undefined,
          type: filters.types.length > 0 ? (filters.types as PortfolioOperationType[]) : undefined,
          instrumentId: filters.instrumentId ?? undefined,
          includeVoided: filters.includeVoided,
          page,
          pageSize: OPERATIONS_PER_PAGE,
        },
        signal,
      });
      return result;
    },
    requestError: operationRequestError,
  });
}
