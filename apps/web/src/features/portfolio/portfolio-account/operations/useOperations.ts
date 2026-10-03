import { listPortfolioOperations, type PortfolioOperationType } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import type { OperationFilterValues } from './operation-filters/operationFilterValues';
import { operationRequestError } from './operationError';

export const OPERATIONS_PER_PAGE = 50;

export function useOperations(accountId: string, filters: OperationFilterValues, page: number) {
  return useQuery({
    queryKey: ['portfolio-operations', accountId, filters, page],
    queryFn: async ({ signal }) => {
      const result = await listPortfolioOperations({
        ...authApiOptions(),
        path: { accountId },
        query: {
          from: filters.from || undefined,
          to: filters.to || undefined,
          type: filters.types.length > 0 ? (filters.types as PortfolioOperationType[]) : undefined,
          instrumentId: filters.instrumentId ?? undefined,
          includeVoided: filters.includeVoided,
          page,
          perPage: OPERATIONS_PER_PAGE,
        },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
    retry: false,
  });
}
