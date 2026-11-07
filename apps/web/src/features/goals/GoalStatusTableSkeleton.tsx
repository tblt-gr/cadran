import type { GoalPeriodicity } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './GoalsPage.module.css';

/** Mirrors the GoalDetailModal table element for element: headers real, months pending. */
export function GoalStatusTableSkeleton({ periodicity }: { periodicity: GoalPeriodicity }) {
  const { t } = useTranslation();
  const yearly = periodicity === 'YEARLY';
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
            {yearly ? (
              <>
                <th scope="col">{t('goals.statusTable.cumulativeValue')}</th>
                <th scope="col">{t('goals.statusTable.cumulativeExpected')}</th>
              </>
            ) : null}
          </tr>
        </thead>
        <SkeletonTableRows columns={yearly ? 6 : 4} rows={12} />
      </table>
    </div>
  );
}
