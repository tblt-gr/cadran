import { readLifeInsuranceContract } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
export function useLifeInsuranceContract(accountId: string, enabled = true) {
  return useQuery({
    queryKey: ['life-insurance-contract', accountId],
    enabled,
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await readLifeInsuranceContract({
        ...authApiOptions(),
        path: { accountId },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
  });
}
