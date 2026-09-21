import {
  explainMonthlyKpi,
  readMonthlyProjection,
  type ExplainMonthlyKpiData,
  type MonthlyKpiExplanation,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

function requestError(result: { response?: Response }) {
  return new Error(String(result.response?.status ?? 0));
}

export function useMonthlyReport(month: string) {
  return useQuery({
    queryKey: ['monthly-report', month],
    queryFn: async ({ signal }) => {
      const result = await readMonthlyProjection({ ...authApiOptions(), query: { month }, signal });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  });
}

type MonthlyKpi = ExplainMonthlyKpiData['path']['kpi'];

export function useMonthlyKpiExplanation(month: string, kpi: MonthlyKpi | null) {
  return useQuery<MonthlyKpiExplanation>({
    enabled: kpi !== null,
    queryKey: ['monthly-kpi-explanation', month, kpi],
    queryFn: async ({ signal }) => {
      const result = await explainMonthlyKpi({
        ...authApiOptions(),
        path: { kpi: kpi! },
        query: { month },
        signal,
      });
      if (!result.response?.ok || !result.data) throw requestError(result);
      return result.data;
    },
    retry: false,
  });
}
