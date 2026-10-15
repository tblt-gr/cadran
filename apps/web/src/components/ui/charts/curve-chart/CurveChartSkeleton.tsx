import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './CurveChart.module.css';

/** CurveChart's frame while its points load: same grid, a pending plot and empty axes. */
export function CurveChartSkeleton() {
  return (
    <div aria-hidden="true" className={styles.frame}>
      <div className={styles.yAxis} />
      <div className={styles.plotBody}>
        <SkeletonBlock className={styles.plot} />
      </div>
      <div className={styles.xAxis} />
    </div>
  );
}
