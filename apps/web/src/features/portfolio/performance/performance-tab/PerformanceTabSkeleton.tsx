import { usePerformanceColumns } from './performance-columns/PerformanceColumns';
import { useMediaQuery } from '@/hooks/use-media-query';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { PerformanceMonthCards } from './performance-month-cards/PerformanceMonthCards';
import { PerformanceMonthTable } from './performance-month-table/PerformanceMonthTable';
import { PerformanceSummary } from './performance-summary/PerformanceSummary';
import { PerformanceToolbar } from './performance-toolbar/PerformanceToolbar';
import styles from './PerformanceTab.module.css';

/** Cold-load layout of the Performance tab: the real chrome, every figure pending. */
export function PerformanceTabSkeleton({
  year,
  selected,
  accountId = '',
}: {
  year: number;
  selected?: string[];
  accountId?: string;
}) {
  const stored = usePerformanceColumns(accountId);
  const visible = selected ?? stored.selected;
  const narrow = useMediaQuery('(max-width: 767px)');
  return (
    <div aria-busy="true" className={styles.tab} data-testid="performance-skeleton">
      <SkeletonStatus>Chargement de la performance…</SkeletonStatus>
      <PerformanceToolbar
        bounds={null}
        onYearChange={() => undefined}
        year={year}
        selected={visible}
        onColumnsChange={() => undefined}
      />
      <PerformanceSummary onExplain={() => undefined} year={year} yearPerformance={null} />
      {narrow ? (
        <PerformanceMonthCards
          selected={visible}
          annual={null}
          onExplain={() => undefined}
          year={year}
        />
      ) : (
        <section className={`card ${styles.panel}`}>
          <PerformanceMonthTable
            selected={visible}
            annual={null}
            onExplain={() => undefined}
            year={year}
          />
        </section>
      )}
    </div>
  );
}
