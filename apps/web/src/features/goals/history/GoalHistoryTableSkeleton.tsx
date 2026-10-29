import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './GoalHistory.module.css';

/** Mirrors GoalHistoryModal's version list: the list is real, every version pending. */
export function GoalHistoryTableSkeleton() {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" data-testid="goal-history-skeleton">
      <SkeletonStatus>{t('goals.loading')}</SkeletonStatus>
      <ol aria-label={t('goals.history.caption')} className={styles.versions}>
        {Array.from({ length: 3 }, (_, index) => (
          <li className={styles.version} key={index}>
            <SkeletonBlock className={styles.skeletonVersion} />
          </li>
        ))}
      </ol>
    </div>
  );
}
