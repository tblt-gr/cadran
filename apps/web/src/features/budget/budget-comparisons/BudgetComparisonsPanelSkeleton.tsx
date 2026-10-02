import {
  SkeletonBlock,
  SkeletonButton,
  SkeletonStatus,
  SkeletonTitle,
} from '@/components/ui/skeleton/Skeleton';
import styles from './BudgetComparisonsPanel.module.css';

export function BudgetComparisonsPanelSkeleton({ status }: { status: string }) {
  return (
    <section
      aria-busy="true"
      className={`card ${styles.card}`}
      data-testid="budget-comparisons-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <div aria-hidden="true" className={styles.heading}>
        <SkeletonTitle />
        <SkeletonButton />
      </div>
      <div aria-hidden="true" className={styles.figures}>
        {Array.from({ length: 3 }, (_, index) => (
          <SkeletonBlock className={styles.skeletonFigure} key={index} />
        ))}
      </div>
    </section>
  );
}
