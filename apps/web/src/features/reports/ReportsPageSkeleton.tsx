import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { MONTHLY_KPIS } from './MonthlyKpiTable';
import styles from './ReportsPage.module.css';

/** Mirrors MonthlyKpiTable element for element: the metric names are real, values pending. */
export function ReportsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <section
      aria-busy="true"
      aria-labelledby="monthly-kpis-skeleton-title"
      className={`card ${styles.panel}`}
      data-testid="monthly-report-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <h2 id="monthly-kpis-skeleton-title">{t('reports.kpis')}</h2>
      <div className={styles.tableWrap}>
        <table>
          <caption className="sr-only">{t('reports.kpis')}</caption>
          <thead>
            <tr>
              <th>{t('reports.metric')}</th>
              <th>{t('reports.value')}</th>
              <th>{t('reports.quality')}</th>
            </tr>
          </thead>
          <tbody>
            {MONTHLY_KPIS.map((kpi) => (
              <tr key={kpi}>
                <th scope="row">{t(`reports.metrics.${kpi}`)}</th>
                <td>
                  <span className={styles.value}>
                    <SkeletonBlock className={styles.skeletonValue} />
                  </span>
                </td>
                <td>
                  <SkeletonBlock className={styles.skeletonQuality} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
