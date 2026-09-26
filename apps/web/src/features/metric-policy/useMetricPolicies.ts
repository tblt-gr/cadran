import {
  activateMetricPolicy,
  createMetricPolicy,
  listMetricPolicies,
  type ActivateMetricPolicyRequest,
  type CreateMetricPolicyRequest,
  type MetricPolicyCatalog,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { metricPolicyRequestError } from './metricPolicyError';

export const metricPoliciesQueryKey = ['metric-policies'] as const;

export function useMetricPolicies() {
  return useQuery<MetricPolicyCatalog>({
    queryKey: metricPoliciesQueryKey,
    queryFn: async ({ signal }) => {
      const result = await listMetricPolicies({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw metricPolicyRequestError(result);
      return result.data;
    },
    retry: false,
  });
}

/**
 * Changing the policy changes what monthly figures mean, so every cached
 * report and budget read is dropped along with the catalog.
 */
function useInvalidateAfterPolicyChange() {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries();
}

export function useCreateMetricPolicy(onDone: () => void) {
  const invalidate = useInvalidateAfterPolicyChange();
  return useMutation({
    mutationFn: async (body: CreateMetricPolicyRequest) => {
      const result = await withCsrfRetry(() => createMetricPolicy({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) throw metricPolicyRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      onDone();
      await invalidate();
    },
  });
}

export function useActivateMetricPolicy(onDone: () => void) {
  const queryClient = useQueryClient();
  const invalidate = useInvalidateAfterPolicyChange();
  return useMutation({
    mutationFn: async (body: ActivateMetricPolicyRequest) => {
      const result = await withCsrfRetry(() => activateMetricPolicy({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) throw metricPolicyRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      onDone();
      await invalidate();
    },
    onError: async () => {
      // A stale or unknown version means the catalog on screen is out of date.
      await queryClient.invalidateQueries({ queryKey: metricPoliciesQueryKey });
    },
  });
}
