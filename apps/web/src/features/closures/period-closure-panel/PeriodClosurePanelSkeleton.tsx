import { SkeletonButton, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './PeriodClosurePanel.module.css';

export function PeriodClosurePanelSkeleton({ status }: { status: string }) {
  return (
    <section aria-busy="true" className={styles.panel} data-testid="period-closure-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div aria-hidden="true" className={styles.summary}>
        <SkeletonButton />
      </div>
      <SkeletonButton className={styles.action} />
    </section>
  );
}
