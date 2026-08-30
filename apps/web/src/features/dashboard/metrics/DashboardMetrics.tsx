import { useTranslation } from 'react-i18next';
import { dashboardDemoData } from '@/features/dashboard/dashboardDemoData';
import { formatDemoMonth } from '@/features/dashboard/formatDemoDate';
import { MetricCard } from './MetricCard';

export function DashboardMetrics() {
  const { i18n, t } = useTranslation();
  const period = formatDemoMonth(dashboardDemoData.currentPeriod, i18n.language);

  return (
    <>
      <MetricCard
        description={t('dashboard.income.description', {
          count: dashboardDemoData.incomeOperationCount,
          period,
        })}
        icon="arrow-down"
        id="income-title"
        title={t('dashboard.income.title')}
        tone="positive"
        value={{ kind: 'money', text: dashboardDemoData.incomeValue }}
      />
      <MetricCard
        description={t('dashboard.spending.description', {
          count: dashboardDemoData.spendingOperationCount,
          period,
        })}
        icon="arrow-up"
        id="spending-title"
        title={t('dashboard.spending.title')}
        tone="negative"
        value={{ kind: 'money', text: dashboardDemoData.spendingValue }}
      />
      <MetricCard
        description={t('states.notCalculable.reason')}
        icon="alert"
        id="saving-title"
        title={t('dashboard.savingRate.title')}
        tone="warning"
        value={{ kind: 'unavailable', text: t('states.notCalculable.label') }}
      />
    </>
  );
}
