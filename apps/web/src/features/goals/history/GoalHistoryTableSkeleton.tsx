import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { GoalHistoryHead } from './GoalHistoryTable';
import styles from './GoalHistory.module.css';

/** Mirrors GoalHistoryTable element for element: caption and headers real, every version pending. */
export function GoalHistoryTableSkeleton() {
  const { t } = useTranslation();
  const cells = [
    'goals.history.modifiedOn',
    'goals.history.effectiveFrom',
    'goals.history.evolution',
  ] as const;
  return (
    <div aria-busy="true" data-testid="goal-history-skeleton">
      <SkeletonStatus>{t('goals.loading')}</SkeletonStatus>
      <div className={styles.tableWrap}>
        <table className={styles.table}>
          <caption>{t('goals.history.caption')}</caption>
          <GoalHistoryHead />
          <tbody>
            {Array.from({ length: 3 }, (_, index) => (
              <tr key={index}>
                <th data-label={t('goals.history.version')} scope="row">
                  <SkeletonBlock className={styles.skeletonVersion} />
                </th>
                {cells.map((label) => (
                  <td data-label={t(label)} key={label}>
                    {label === 'goals.history.effectiveFrom' ? (
                      <div className={styles.effectiveFrom}>
                        <span className={styles.effectiveDate}>
                          <SkeletonBlock className={styles.skeletonVersion} />
                        </span>
                      </div>
                    ) : (
                      <SkeletonBlock className={styles.skeletonVersion} />
                    )}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
