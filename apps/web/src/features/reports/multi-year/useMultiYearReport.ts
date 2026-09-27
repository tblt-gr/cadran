import { readMultiYearReport } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { AnnualRequestError } from '@/features/reports/annual/useAnnualReport';

export { AnnualRequestError as MultiYearRequestError } from '@/features/reports/annual/useAnnualReport';

function requestError(result: { response?: Response }) {
  return new AnnualRequestError(result.response?.status ?? 0);
}

/**
 * `from`/`to` omitted let the backend apply its own defaults (the workspace's
 * first data year to the current year), which is how the page learns the
 * true range bounds before the caller narrows them.
 */
export function useMultiYearReport(from?: number, to?: number) {
  return useQuery({
    queryKey: ['multi-year-report', from ?? null, to ?? null],
    queryFn: async ({ signal }) => {
      const query: { from?: number; to?: number } = {};
      if (from !== undefined) query.from = from;
      if (to !== undefined) query.to = to;
      const result = await readMultiYearReport({ ...authApiOptions(), query, signal });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  });
}
