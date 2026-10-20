import type { AccountValuationHistoryPoint } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { historyAmount, historyQuality, historyProvenance } from './savingsHistoryLabels';
import styles from './SavingsHistoryPanel.module.css';

/** Missing points are retained with their reason; table figures keep every server digit. */
export function SavingsHistoryTable({ points }: { points: AccountValuationHistoryPoint[] | null }) {
  const { t, i18n } = useTranslation();
  const columns = [
    t('accounts.history.date'),
    t('accounts.history.balance'),
    t('accounts.history.quality'),
    t('accounts.history.provenance'),
  ];
  return (
    <div
      className={styles.scroll}
      role="region"
      aria-label={t('accounts.history.table')}
      tabIndex={0}
    >
      <table>
        <caption className="sr-only">{t('accounts.history.table')}</caption>
        <thead>
          <tr>
            {columns.map((label) => (
              <th scope="col" key={label}>
                {label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {(points ?? [null, null, null]).map((point, index) => (
            <tr key={point?.date ?? index}>
              <th scope="row">
                <time dateTime={point?.date}>
                  {point ? formatCalendarNumericDay(point.date) : <SkeletonBlock />}
                </time>
              </th>
              <td data-label={columns[1]}>
                {point ? historyAmount(point, i18n.language) : <SkeletonBlock />}
              </td>
              <td data-label={columns[2]}>
                {point ? historyQuality(point, t) : <SkeletonBlock />}
              </td>
              <td data-label={columns[3]}>
                {point ? historyProvenance(point, t) : <SkeletonBlock />}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
