import { SkeletonBlock, SkeletonButton, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './PeriodClosurePanel.module.css';

/** Mirrors the closure summary and its action: the state badge and the action are pending. */
export function PeriodClosurePanelSkeleton({ status }: { status: string }) {
  return (
    <div aria-busy="true" className={styles.panel} data-testid="period-closure-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div aria-hidden="true" className={styles.summary}>
        <SkeletonBlock className={styles.skeletonBadge} />
      </div>
      <SkeletonButton className={styles.action} />
    </div>
  );
}
