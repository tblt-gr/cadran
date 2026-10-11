import type { MonthlyProjectionMetric } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { formatMonthLabel } from '@/features/reports/MonthlyMonthNavigator';
import { useMonthlyReport } from '@/features/reports/useMonthlyReport';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { workspaceToday } from '@/lib/workspaceTime';
import { MetricCard } from './MetricCard';

type CardValue = Parameters<typeof MetricCard>[0]['value'];

/**
 * The dashboard reads the workspace's current month from the monthly projection:
 * the figures are the report's own, never recomputed here.
 */
export function DashboardMetrics() {
  const { i18n, t } = useTranslation();
  const timeZone = useWorkspaceTimeZone();
  const month = workspaceToday(new Date(), timeZone).slice(0, 7);
  const report = useMonthlyReport(month);
  const period = t('dashboard.currentMonth', { month: formatMonthLabel(month, i18n.language) });

  function toValue(metric: MonthlyProjectionMetric | undefined, rate: boolean): CardValue {
    if (report.isPending) return { kind: 'loading' };
    if (report.isError || metric === undefined) {
      return { kind: 'unavailable', text: t('states.error.title') };
    }
    if (metric.value === null)
      return { kind: 'unavailable', text: t('states.notCalculable.label') };
    if (rate && metric.assetCode === null) {
      return { kind: 'money', text: formatRatioPercentage(metric.value, i18n.language) };
    }
    if (metric.assetCode === null) {
      return { kind: 'unavailable', text: t('states.notCalculable.label') };
    }
    return { kind: 'money', text: formatAmount(metric.value, metric.assetCode, i18n.language) };
  }

  const data = report.data;
  const savingRate = toValue(data?.cashSavingsRate, true);

  return (
    <>
      <MetricCard
        description={period}
        icon="arrow-down"
        id="income-title"
        title={t('dashboard.income.title')}
        tone="positive"
        value={toValue(data?.cashIncome, false)}
      />
      <MetricCard
        description={period}
        icon="arrow-up"
        id="spending-title"
        title={t('dashboard.spending.title')}
        tone="negative"
        value={toValue(data?.budgetExpenses, false)}
      />
      <MetricCard
        description={savingRate.kind === 'unavailable' ? t('states.notCalculable.reason') : period}
        icon={savingRate.kind === 'unavailable' ? 'alert' : 'goals'}
        id="saving-title"
        title={t('dashboard.savingRate.title')}
        tone={savingRate.kind === 'unavailable' ? 'warning' : 'positive'}
        value={savingRate}
      />
    </>
  );
}
