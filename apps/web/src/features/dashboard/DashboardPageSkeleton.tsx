import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { AllocationChartsSkeleton } from './allocation-charts/AllocationChartsSkeleton';
import allocationStyles from './allocation-panel/AllocationPanel.module.css';
import cardStyles from './net-worth-card/NetWorthCard.module.css';
import { NetWorthPeriodControl } from './net-worth-period/NetWorthPeriodControl';
import type { NetWorthPeriod } from './net-worth-period/useNetWorthPeriod';
import { WealthChartSkeleton } from './wealth-chart/WealthChartSkeleton';
import styles from './DashboardPageSkeleton.module.css';

/**
 * Mirrors NetWorthCard and AllocationPanel element for element. Titles,
 * descriptions, links and chart controls are static and real: only the
 * figures and the plots are pending.
 */
export function DashboardPageSkeleton({
  status,
  period = 'LAST_12_MONTHS',
}: {
  status: string;
  period?: NetWorthPeriod;
}) {
  const { t } = useTranslation();
  return (
    <>
      <section
        aria-busy="true"
        aria-labelledby="net-worth-skeleton-title"
        className={`card ${cardStyles.card}`}
        data-testid="dashboard-skeleton"
      >
        <SkeletonStatus>{status}</SkeletonStatus>
        <div className={cardStyles.header}>
          <div>
            <h2 id="net-worth-skeleton-title">{t('dashboard.netWorth.title')}</h2>
            <SkeletonBlock className={styles.amount} />
            <SkeletonBlock className={styles.delta} />
          </div>
          <div className={cardStyles.context}>
            <SkeletonBlock className={styles.badge} />
            <SkeletonBlock className={styles.asOf} />
          </div>
        </div>
        <div className={cardStyles.history} aria-busy="true">
          <NetWorthPeriodControl period={period} disabled />
          <WealthChartSkeleton />
          <p className={cardStyles.notice} />
        </div>
      </section>
      <section
        aria-labelledby="allocation-skeleton-title"
        className={`card ${allocationStyles.card}`}
        data-allocation-view="treemap"
      >
        <div className={allocationStyles.heading}>
          <div>
            <h2 id="allocation-skeleton-title">{t('dashboard.allocation.title')}</h2>
            <p>{t('dashboard.allocation.description')}</p>
          </div>
          <a className={allocationStyles.link} href="/accounts/groups">
            {t('dashboard.allocation.viewGroups')}
          </a>
        </div>
        <div className={allocationStyles.treeLayout}>
          <AllocationChartsSkeleton />
        </div>
      </section>
    </>
  );
}
