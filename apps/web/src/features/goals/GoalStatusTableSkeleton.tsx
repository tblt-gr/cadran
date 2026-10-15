import { useTranslation } from 'react-i18next';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './GoalsPage.module.css';

/** Mirrors the GoalDetailModal table element for element: headers real, months pending. */
export function GoalStatusTableSkeleton() {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.statusTableWrap} data-testid="goal-status-skeleton">
      <SkeletonStatus>{t('goals.loading')}</SkeletonStatus>
      <table className={styles.statusTable}>
        <caption className="sr-only">{t('goals.statusTable.caption')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('goals.statusTable.month')}</th>
            <th scope="col">{t('goals.statusTable.state')}</th>
            <th scope="col">{t('goals.statusTable.value')}</th>
            <th scope="col">{t('goals.statusTable.expected')}</th>
            <th scope="col">{t('goals.statusTable.cumulativeValue')}</th>
            <th scope="col">{t('goals.statusTable.cumulativeExpected')}</th>
          </tr>
        </thead>
        <SkeletonTableRows columns={6} rows={12} />
      </table>
    </div>
  );
}
