import { useTranslation } from 'react-i18next';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './GoalHistory.module.css';

/** Mirrors the GoalHistoryModal table element for element: caption and headers real, versions pending. */
export function GoalHistoryTableSkeleton() {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.history} data-testid="goal-history-skeleton">
      <SkeletonStatus>{t('goals.loading')}</SkeletonStatus>
      <div className={styles.tableWrap}>
        <table className={styles.table}>
          <caption>{t('goals.history.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('goals.history.version')}</th>
              <th scope="col">{t('goals.history.effectiveFrom')}</th>
              <th scope="col">{t('goals.history.author')}</th>
              <th scope="col">{t('goals.history.createdAt')}</th>
              <th scope="col">{t('goals.history.reason')}</th>
            </tr>
          </thead>
          <SkeletonTableRows columns={5} rows={3} />
        </table>
      </div>
    </div>
  );
}
