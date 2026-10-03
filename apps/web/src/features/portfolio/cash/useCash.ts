import { readPortfolioCash } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

export function useCash(accountId: string, from: string, to: string) {
  return useQuery({
    queryKey: ['portfolio-cash', accountId, from, to],
    queryFn: async ({ signal }) => {
      const result = await readPortfolioCash({
        ...authApiOptions(),
        path: { accountId },
        query: { from, to },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
    retry: false,
  });
}
