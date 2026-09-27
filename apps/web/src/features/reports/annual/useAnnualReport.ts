import {
  explainAnnualReportColumn,
  readAnnualReport,
  readAnnualReportPreferences,
  saveAnnualReportPreferences,
  type AnnualReportPreferencesInput,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';

export const ANNUAL_PREFERENCES_QUERY_KEY = ['annual-report-preferences'];

export class AnnualRequestError extends Error {
  readonly status: number;

  constructor(status: number) {
    super(String(status));
    this.status = status;
  }
}

function requestError(result: { response?: Response }) {
  return new AnnualRequestError(result.response?.status ?? 0);
}

export function useAnnualReport(year: number) {
  return useQuery({
    queryKey: ['annual-report', year],
    queryFn: async ({ signal }) => {
      const result = await readAnnualReport({ ...authApiOptions(), path: { year }, signal });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  });
}

export function useAnnualReportPreferences() {
  return useQuery({
    queryKey: ANNUAL_PREFERENCES_QUERY_KEY,
    queryFn: async ({ signal }) => {
      const result = await readAnnualReportPreferences({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  });
}

/**
 * A stale version is refused with 409: the cached preferences are reloaded so the
 * caller never resubmits the same stale version blindly.
 */
export function useSaveAnnualReportPreferences() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: AnnualReportPreferencesInput) => {
      const result = await withCsrfRetry(() =>
        saveAnnualReportPreferences({ ...authApiOptions(), body: input }),
      );
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    onError: () => {
      void queryClient.invalidateQueries({ queryKey: ANNUAL_PREFERENCES_QUERY_KEY });
    },
    onSuccess: (data) => {
      queryClient.setQueryData(ANNUAL_PREFERENCES_QUERY_KEY, data);
      void queryClient.invalidateQueries({ queryKey: ['annual-report'] });
    },
  });
}

export function useAnnualColumnExplanation(year: number, columnId: string | null) {
  return useQuery({
    enabled: columnId !== null,
    queryKey: ['annual-report-explanation', year, columnId],
    queryFn: async ({ signal }) => {
      const result = await explainAnnualReportColumn({
        ...authApiOptions(),
        path: { year, columnId: columnId! },
        signal,
      });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  });
}
