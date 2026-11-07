import {
  readInterfacePreferences,
  saveInterfacePreferences,
  type InterfacePreferences,
  type InterfacePreferencesInput,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { budgetRequestError } from '@/features/budget/budgetError';

const interfacePreferencesQueryKey = ['interface-preferences'] as const;

/** Every read family whose figures change when planned transactions start or stop counting. */
const UPCOMING_QUERY_FAMILIES = [
  'monthly-report',
  'monthly-recap',
  'monthly-ledger',
  'monthly-ledger-movements',
  'monthly-kpi-explanation',
  'budget-kpi-explanation',
  'summary',
];

/** The workspace's interface preferences, identical on every device. */
export function useInterfacePreferences() {
  return useQuery<InterfacePreferences>({
    queryKey: interfacePreferencesQueryKey,
    queryFn: async ({ signal }) => {
      const result = await readInterfacePreferences({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw budgetRequestError(result);
      return result.data;
    },
    retry: false,
  });
}

/**
 * Saves with the stored version. A stale version (409) is never retried blindly: the cached
 * preferences are refetched so the workspace's current value and version come back first.
 */
export function useSaveInterfacePreferences() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: InterfacePreferencesInput) => {
      const result = await withCsrfRetry(() =>
        saveInterfacePreferences({ ...authApiOptions(), body: input }),
      );
      if (!result.response?.ok || !result.data) throw budgetRequestError(result);
      return result.data;
    },
    onError: () => {
      void queryClient.invalidateQueries({ queryKey: interfacePreferencesQueryKey });
    },
    onSuccess: async (data) => {
      queryClient.setQueryData(interfacePreferencesQueryKey, data);
      await Promise.all(
        UPCOMING_QUERY_FAMILIES.map((key) => queryClient.invalidateQueries({ queryKey: [key] })),
      );
    },
  });
}
