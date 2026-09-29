import {
  readEmergencyFundCoverage,
  readEmergencyFundPolicy,
  updateEmergencyFundPolicy,
  type UpdateEmergencyFundPolicyRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { goalRequestError } from '@/features/goals/goalError';

const COVERAGE_KEY = ['emergency-fund'] as const;
const POLICY_KEY = ['emergency-fund-policy'] as const;

export function useEmergencyFundCoverage() {
  return useQuery({
    queryKey: COVERAGE_KEY,
    queryFn: async ({ signal }) => {
      const result = await readEmergencyFundCoverage({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    retry: false,
  });
}

export function useEmergencyFundPolicy(enabled: boolean) {
  return useQuery({
    enabled,
    queryKey: POLICY_KEY,
    queryFn: async ({ signal }) => {
      const result = await readEmergencyFundPolicy({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    retry: false,
  });
}

/** Saves the policy, then refreshes both the policy and the coverage it drives. */
export function useUpdateEmergencyFundPolicy() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (body: UpdateEmergencyFundPolicyRequest) => {
      const result = await withCsrfRetry(() =>
        updateEmergencyFundPolicy({ ...authApiOptions(), body }),
      );
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    onSettled: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: POLICY_KEY }),
        queryClient.invalidateQueries({ queryKey: COVERAGE_KEY }),
      ]);
    },
  });
}
