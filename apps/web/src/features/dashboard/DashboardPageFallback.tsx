import { useTranslation } from 'react-i18next';
import { MetricCard } from './metrics/MetricCard';
import { DashboardPageSkeleton } from './DashboardPageSkeleton';
import styles from './DashboardPage.module.css';

/**
 * The dashboard while its page chunk is still in flight: the same grid, cards
 * and metric chrome as DashboardPage in its loading state. It stays outside the
 * lazy chunk so the landing page never shows a layout of its own invention.
 */
export function DashboardPageFallback() {
  const { t } = useTranslation();
  const loading = { kind: 'loading' } as const;

  return (
    <div className={styles.grid}>
      <DashboardPageSkeleton status={t('routes.pageLoading')} />
      <MetricCard
        description={null}
        icon="arrow-down"
        id="income-title"
        title={t('dashboard.income.title')}
        tone="positive"
        value={loading}
      />
      <MetricCard
        description={null}
        icon="arrow-up"
        id="spending-title"
        title={t('dashboard.spending.title')}
        tone="negative"
        value={loading}
      />
      <MetricCard
        description={null}
        icon="goals"
        id="saving-title"
        title={t('dashboard.savingRate.title')}
        tone="positive"
        value={loading}
      />
    </div>
  );
}
