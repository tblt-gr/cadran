import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus, SkeletonTitle } from '@/components/ui/skeleton/Skeleton';
import styles from './GoalsPage.module.css';
import skeletonStyles from './GoalsPageSkeleton.module.css';

/** Mirrors GoalCard element for element: every value depends on the goal, so all are pending. */
function GoalCardSkeleton() {
  return (
    <article aria-hidden="true" className={`card ${styles.goalCard}`}>
      <div className={styles.cardHeading}>
        <div className={styles.goalIdentity}>
          <SkeletonTitle size="page" />
          <SkeletonBlock className={skeletonStyles.metric} />
        </div>
        <SkeletonBlock className={skeletonStyles.menu} />
      </div>
      <div className={styles.position}>
        <p className={styles.amounts}>
          <strong className="money">
            <SkeletonBlock className={skeletonStyles.amount} />
          </strong>
        </p>
        <p className={styles.gap}>
          <SkeletonBlock className={skeletonStyles.direction} />
        </p>
      </div>
      <SkeletonBlock className={skeletonStyles.progress} />
      <p className={styles.caption}>
        <SkeletonBlock className={skeletonStyles.direction} />
      </p>
      <div className={styles.statusHeading}>
        <p className={styles.statusMonth}>
          <SkeletonBlock className={skeletonStyles.direction} />
        </p>
        <SkeletonBlock className={skeletonStyles.badge} />
      </div>
    </article>
  );
}

export function GoalsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <section
      aria-busy="true"
      aria-label={t('goals.list')}
      className={styles.list}
      data-testid="goals-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.grid}>
        {Array.from({ length: 2 }, (_, index) => (
          <GoalCardSkeleton key={index} />
        ))}
      </div>
    </section>
  );
}
