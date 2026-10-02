import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './ReportsPage.module.css';

export function ReportsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <section
      aria-busy="true"
      className={`card ${styles.state}`}
      data-testid="monthly-report-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <h2>{t('reports.kpis')}</h2>
      <div className={styles.tableWrap}>
        <table className={styles.skeletonTable} role="presentation">
          <thead>
            <tr>
              <th>{t('reports.metric')}</th>
              <th>{t('reports.value')}</th>
              <th>{t('reports.quality')}</th>
            </tr>
          </thead>
          <SkeletonTableRows columns={3} rows={15} />
        </table>
      </div>
    </section>
  );
}
import { useTranslation } from 'react-i18next';
