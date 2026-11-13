import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonChartArea } from '@/components/ui/skeleton/Skeleton';
import { GroupingModeToggle } from '@/features/accounts/grouping-mode/GroupingModeToggle';
import { AnnualCharts } from '@/features/reports/annual/AnnualCharts';
import { ChartFrame } from '@/features/reports/annual/ChartFrame';
import styles from '@/features/reports/annual/AnnualCharts.module.css';
import { overviewTopCategories } from './multiYearOverview';

/**
 * One overview for the whole range: the top expense categories summed over every selected year,
 * and the allocation at the end of the last year.
 */
export function MultiYearOverview({
  from,
  reports,
  to,
}: {
  from: number;
  reports: AnnualReport[] | null;
  to: number;
}) {
  const { t } = useTranslation();
  const title = t('reports.multiYear.overview', { from, to });
  const last = reports?.[reports.length - 1];

  return (
    <section aria-label={title}>
      <h2>{title}</h2>
      {reports && last ? (
        <AnnualCharts
          includeTrends={false}
          report={{
            ...last,
            charts: { ...last.charts, topExpenseCategories: overviewTopCategories(reports) },
          }}
        />
      ) : (
        <div className={styles.grid}>
          <ChartFrame title={t('reports.annual.charts.topCategories')}>
            <SkeletonChartArea />
          </ChartFrame>
          <ChartFrame
            actions={<GroupingModeToggle disabled mode="PRIMARY" onChange={() => undefined} />}
            title={t('reports.annual.charts.allocation')}
          >
            <SkeletonChartArea />
          </ChartFrame>
        </div>
      )}
    </section>
  );
}
