import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus, SkeletonTitle } from '@/components/ui/skeleton/Skeleton';
import styles from './BudgetComparisonsPanel.module.css';

const FIGURES = [
  'budget.comparisons.actual',
  'budget.comparisons.target',
  'budget.comparisons.variance',
] as const;

/** Mirrors the comparison list element for element: figure labels are real, values pending. */
export function BudgetComparisonsPanelSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();

  return (
    <div aria-busy="true" className={styles.list} data-testid="budget-comparisons-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      {Array.from({ length: 2 }, (_, index) => (
        <article className={`card ${styles.card}`} key={index}>
          <div className={styles.heading}>
            <SkeletonTitle />
            <SkeletonBlock className={styles.skeletonBadge} />
          </div>
          <dl className={styles.figures}>
            {FIGURES.map((key) => (
              <div key={key}>
                <dt>{t(key)}</dt>
                <dd>
                  <SkeletonBlock className={styles.skeletonFigure} />
                </dd>
              </div>
            ))}
          </dl>
          <details className={styles.policy}>
            <summary>{t('budget.comparisons.policy.summary')}</summary>
            <p>{t('budget.comparisons.policy.description')}</p>
          </details>
          <SkeletonBlock className={styles.skeletonSources} />
        </article>
      ))}
    </div>
  );
}
