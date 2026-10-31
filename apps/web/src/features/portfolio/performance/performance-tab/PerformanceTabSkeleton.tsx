import { useMediaQuery } from '@/hooks/use-media-query';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { PerformanceMonthCards } from './performance-month-cards/PerformanceMonthCards';
import { PerformanceMonthTable } from './performance-month-table/PerformanceMonthTable';
import { PerformanceSummary } from './performance-summary/PerformanceSummary';
import { YearNavigator } from './year-navigator/YearNavigator';
import styles from './PerformanceTab.module.css';

/** Cold-load layout of the Performance tab: the real chrome, every figure pending. */
export function PerformanceTabSkeleton({ year }: { year: number }) {
  const narrow = useMediaQuery('(max-width: 767px)');
  return (
    <div aria-busy="true" className={styles.tab} data-testid="performance-skeleton">
      <SkeletonStatus>Chargement de la performance…</SkeletonStatus>
      <YearNavigator bounds={null} onChange={() => undefined} year={year} />
      <PerformanceSummary onExplain={() => undefined} year={year} yearPerformance={null} />
      {narrow ? (
        <PerformanceMonthCards annual={null} onExplain={() => undefined} year={year} />
      ) : (
        <section className={`card ${styles.panel}`}>
          <PerformanceMonthTable annual={null} onExplain={() => undefined} year={year} />
        </section>
      )}
    </div>
  );
}
