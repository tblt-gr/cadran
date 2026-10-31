import { YearNavigator } from '@/features/portfolio/performance/performance-tab/year-navigator/YearNavigator';
import { PerformanceColumns } from '@/features/portfolio/performance/performance-tab/performance-columns/PerformanceColumns';
import styles from './PerformanceToolbar.module.css';

export function PerformanceToolbar({
  year,
  bounds,
  onYearChange,
  selected,
  onColumnsChange,
}: {
  year: number;
  bounds: { from: number; to: number; years: number[] } | null;
  onYearChange: (year: number) => void;
  selected: string[];
  onColumnsChange: (selected: string[]) => void;
}) {
  return (
    <div className={styles.toolbar}>
      <YearNavigator year={year} bounds={bounds} onChange={onYearChange} />
      <PerformanceColumns selected={selected} onChange={onColumnsChange} disabled={!bounds} />
    </div>
  );
}
