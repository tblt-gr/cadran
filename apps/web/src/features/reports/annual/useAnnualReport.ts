import {
  explainAnnualReportColumn,
  readAnnualReport,
  readAnnualReportPreferences,
  saveAnnualReportPreferences,
  type AnnualReportPreferencesInput,
  type AnnualReportPreferences,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';

/**
 * The current, still incomplete month always counts in the figures; a future month never does,
 * whatever this says (the backend excludes it). Sent on every read so the stored preference,
 * which only picks columns for the user, cannot drop the running month.
 */
export const INCOMPLETE_MONTHS_INCLUDED = { incompleteMonths: 'include' } as const;

const ANNUAL_PREFERENCES_QUERY_KEY = ['annual-report-preferences'];

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

export function annualReportQueryOptions(year: number) {
  return {
    queryKey: ['annual-report', year],
    queryFn: async ({ signal }: { signal: AbortSignal }) => {
      const result = await readAnnualReport({
        ...authApiOptions(),
        path: { year },
        query: INCOMPLETE_MONTHS_INCLUDED,
        signal,
      });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  };
}

export function useAnnualReport(year: number) {
  return useQuery(annualReportQueryOptions(year));
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
      const previous = queryClient.getQueryData<AnnualReportPreferences>(
        ANNUAL_PREFERENCES_QUERY_KEY,
      );
      queryClient.setQueryData(ANNUAL_PREFERENCES_QUERY_KEY, data);
      // Mark visibility does not change report figures. Refetching here would also restart an
      // enabled evaluation before its observers can react to the newly disabled preference.
      if (
        previous &&
        previous.incompleteMonths === data.incompleteMonths &&
        previous.columns.length === data.columns.length &&
        previous.columns.every((column, index) => column === data.columns[index])
      )
        return;
      void queryClient.invalidateQueries({ queryKey: ['annual-report'] });
      void queryClient.invalidateQueries({ queryKey: ['multi-year-report'] });
      void queryClient.invalidateQueries({ queryKey: ['goal-evaluations'] });
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
        query: INCOMPLETE_MONTHS_INCLUDED,
        signal,
      });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  });
}
