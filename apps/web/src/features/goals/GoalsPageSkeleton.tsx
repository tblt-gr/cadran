import {
  SkeletonBlock,
  SkeletonButton,
  SkeletonStatus,
  SkeletonTitle,
} from '@/components/ui/skeleton/Skeleton';
import styles from './GoalsPage.module.css';
import skeletonStyles from './GoalsPageSkeleton.module.css';

function GoalCardSkeleton() {
  return (
    <div aria-hidden="true" className={`card ${styles.goalCard}`}>
      <div className={styles.cardHeading}>
        <div className={styles.goalIdentity}>
          <SkeletonTitle size="page" />
          <SkeletonBlock className={skeletonStyles.metric} />
        </div>
        <SkeletonBlock className={skeletonStyles.menu} />
      </div>
      <div className={styles.value}>
        <SkeletonBlock className={skeletonStyles.direction} />
        <SkeletonBlock className={skeletonStyles.amount} />
      </div>
      <div className={styles.statusHeading}>
        <SkeletonBlock className={skeletonStyles.direction} />
        <SkeletonButton className={skeletonStyles.badge} />
      </div>
      <SkeletonBlock className={skeletonStyles.progress} />
    </div>
  );
}

export function GoalsPageSkeleton({ status }: { status: string }) {
  return (
    <section aria-busy="true" className={styles.list} data-testid="goals-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.grid}>
        {Array.from({ length: 2 }, (_, index) => (
          <GoalCardSkeleton key={index} />
        ))}
      </div>
    </section>
  );
}
