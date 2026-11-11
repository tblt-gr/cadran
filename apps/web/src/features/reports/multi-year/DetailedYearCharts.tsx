import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonChartArea } from '@/components/ui/skeleton/Skeleton';
import { GroupingModeToggle } from '@/features/accounts/grouping-mode/GroupingModeToggle';
import { AnnualCharts } from '@/features/reports/annual/AnnualCharts';
import { ChartFrame } from '@/features/reports/annual/ChartFrame';
import styles from '@/features/reports/annual/AnnualCharts.module.css';

/** Each annual category chart keeps its own period and each allocation its own period end. */
export function DetailedYearCharts({
  years,
  reports,
}: {
  years: number[];
  reports: AnnualReport[] | null;
}) {
  const { t } = useTranslation();
  return years.map((year) => {
    const report = reports?.find((item) => item.year === year);
    return (
      <section key={year} aria-label={t('reports.annual.caption', { year })}>
        <h2>{t('reports.annual.caption', { year })}</h2>
        {report ? (
          <AnnualCharts includeTrends={false} report={report} />
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
  });
}
