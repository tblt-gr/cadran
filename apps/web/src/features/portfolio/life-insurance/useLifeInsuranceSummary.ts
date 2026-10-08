import { readLifeInsuranceSummary } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
export function useLifeInsuranceSummary(accountId: string, asOf: string, enabled = true) {
  return useQuery({
    queryKey: ['life-insurance-summary', accountId, asOf],
    enabled,
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await readLifeInsuranceSummary({
        ...authApiOptions(),
        path: { accountId },
        query: { asOf },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
  });
}
