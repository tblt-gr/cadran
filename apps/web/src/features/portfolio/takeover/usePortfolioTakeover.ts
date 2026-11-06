import { readPortfolioTakeover } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

/** Whether the account can still take over its positions, and the takeover once done. */
export function usePortfolioTakeover(accountId: string, enabled = true) {
  return useQuery({
    queryKey: ['portfolio-takeover', accountId],
    enabled,
    queryFn: async () => {
      const result = await readPortfolioTakeover({ ...authApiOptions(), path: { accountId } });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
    retry: false,
  });
}
