import { useTranslation } from 'react-i18next';
import {
  SkeletonBlock,
  SkeletonChartArea,
  SkeletonStatus,
} from '@/components/ui/skeleton/Skeleton';
import allocationStyles from './allocation-panel/AllocationPanel.module.css';
import cardStyles from './net-worth-card/NetWorthCard.module.css';
import styles from './DashboardPageSkeleton.module.css';

/** Titles, descriptions and links are static and real: only the figures and charts are pending. */
export function DashboardPageSkeleton({ status }: { status: string }) {
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
        <SkeletonChartArea className={styles.chart} />
      </section>
      <section
        aria-labelledby="allocation-skeleton-title"
        className={`card ${allocationStyles.card}`}
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
        <SkeletonChartArea />
      </section>
    </>
  );
}
