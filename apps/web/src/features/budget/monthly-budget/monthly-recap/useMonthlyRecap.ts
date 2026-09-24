import {
  readMonthlyRecap,
  readMonthlyRecapPreferences,
  saveMonthlyRecapPreferences,
  type MonthlyRecapPreferencesInput,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { budgetRequestError } from '@/features/budget/budgetError';

export const RECAP_PREFERENCES_QUERY_KEY = ['monthly-recap-preferences'];

export function useMonthlyRecap(month: string) {
  return useQuery({
    queryKey: ['monthly-recap', month],
    queryFn: async ({ signal }) => {
      const result = await readMonthlyRecap({ ...authApiOptions(), query: { month }, signal });
      if (!result.response?.ok || !result.data) throw budgetRequestError(result);
      return result.data;
    },
    retry: false,
  });
}

export function useRecapPreferences() {
  return useQuery({
    queryKey: RECAP_PREFERENCES_QUERY_KEY,
    queryFn: async ({ signal }) => {
      const result = await readMonthlyRecapPreferences({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw budgetRequestError(result);
      return result.data;
    },
    retry: false,
  });
}

/**
 * Persists the recap visibility selection. A stale version is refused with a
 * 409 by the backend; the caller never retries blindly with the same version,
 * it invalidates the cached preferences so the workspace's current selection
 * and version come back before another save is attempted.
 */
export function useSaveRecapPreferences() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: MonthlyRecapPreferencesInput) => {
      const result = await withCsrfRetry(() =>
        saveMonthlyRecapPreferences({ ...authApiOptions(), body: input }),
      );
      if (!result.response?.ok || !result.data) throw budgetRequestError(result);
      return result.data;
    },
    onError: () => {
      void queryClient.invalidateQueries({ queryKey: RECAP_PREFERENCES_QUERY_KEY });
    },
    onSuccess: (data) => {
      queryClient.setQueryData(RECAP_PREFERENCES_QUERY_KEY, data);
    },
  });
}
