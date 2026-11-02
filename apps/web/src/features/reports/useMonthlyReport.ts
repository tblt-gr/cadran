import {
  explainMonthlyKpi,
  readMonthlyProjection,
  type ExplainMonthlyKpiData,
  type MonthlyKpiExplanation,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { useIncludeUpcoming } from '@/features/settings/interface/useIncludeUpcoming';

function requestError(result: { response?: Response }) {
  return new Error(String(result.response?.status ?? 0));
}

export function useMonthlyReport(month: string, enabled = true) {
  const upcoming = useIncludeUpcoming();

  return useQuery({
    enabled: enabled && upcoming.ready,
    queryKey: ['monthly-report', month, upcoming.includeUpcoming],
    queryFn: async ({ signal }) => {
      const result = await readMonthlyProjection({
        ...authApiOptions(),
        query: { month, includeUpcoming: upcoming.includeUpcoming },
        signal,
      });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  });
}

type MonthlyKpi = ExplainMonthlyKpiData['path']['kpi'];

export function useMonthlyKpiExplanation(month: string, kpi: MonthlyKpi | null) {
  const upcoming = useIncludeUpcoming();

  return useQuery<MonthlyKpiExplanation>({
    enabled: kpi !== null && upcoming.ready,
    queryKey: ['monthly-kpi-explanation', month, kpi, upcoming.includeUpcoming],
    queryFn: async ({ signal }) => {
      const result = await explainMonthlyKpi({
        ...authApiOptions(),
        path: { kpi: kpi! },
        query: { month, includeUpcoming: upcoming.includeUpcoming },
        signal,
      });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  });
}
