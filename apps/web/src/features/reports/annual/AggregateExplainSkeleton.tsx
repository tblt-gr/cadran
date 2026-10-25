import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { monthLabel } from './annualRowLabels';
import styles from './AggregateExplainModal.module.css';

const MONTHS = Array.from({ length: 12 }, (_, index) => String(index + 1).padStart(2, '0'));

/** Mirrors the loaded explanation element for element: labels and months real, figures pending. */
export function AggregateExplainSkeleton({ year }: { year: number }) {
  const { i18n, t } = useTranslation();

  return (
    <div aria-busy="true" className={styles.body} data-testid="aggregate-explain-skeleton">
      <SkeletonStatus>{t('reports.explanationLoading')}</SkeletonStatus>
      <table aria-hidden="true" className={styles.months}>
        <caption>{t('reports.annual.explainMonths')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('reports.annual.month')}</th>
            <th scope="col">{t('reports.value')}</th>
            <th scope="col">{t('reports.annual.counted')}</th>
          </tr>
        </thead>
        <tbody>
          {MONTHS.map((month) => (
            <tr key={month}>
              <th scope="row">{monthLabel(`${year}-${month}`, i18n.language)}</th>
              <td>
                <SkeletonBlock className={styles.skeletonValue} />
              </td>
              <td>
                <SkeletonBlock className={styles.skeletonValue} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <dl className={styles.facts}>
        <dt>{t('reports.formula')}</dt>
        <dd>
          <SkeletonBlock className={styles.skeletonValue} />
        </dd>
        <dt>{t('reports.annual.policy')}</dt>
        <dd>
          <SkeletonBlock className={styles.skeletonValue} />
        </dd>
      </dl>
    </div>
  );
}
