import { listPortfolioPositions } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

export function usePositions(accountId: string, asOf: string, includeClosed: boolean) {
  return useQuery({
    queryKey: ['portfolio-positions', accountId, asOf, includeClosed],
    queryFn: async ({ signal }) => {
      const result = await listPortfolioPositions({
        ...authApiOptions(),
        path: { accountId },
        query: { asOf, includeClosed },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
    retry: false,
  });
}
