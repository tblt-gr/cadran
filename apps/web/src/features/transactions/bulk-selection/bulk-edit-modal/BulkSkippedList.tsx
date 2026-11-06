import type { BulkSkipReason, BulkSkippedTransaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatCalendarWeekdayDay } from '@/lib/decimal';
import type { BulkSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import styles from './BulkEditModal.module.css';

interface BulkSkippedListProps {
  pageItems: BulkSelection['pageItems'];
  skipped: readonly BulkSkippedTransaction[];
}

/** Every skipped transaction, grouped under the reason the server gave. */
export function BulkSkippedList({ pageItems, skipped }: BulkSkippedListProps) {
  const { i18n, t } = useTranslation();
  const byReason = new Map<BulkSkipReason, string[]>();
  for (const row of skipped) {
    byReason.set(row.reason, [...(byReason.get(row.reason) ?? []), row.id]);
  }

  function describe(id: string): string {
    const row = pageItems.find((item) => item.id === id);
    return row
      ? `${row.displayLabel}, ${formatCalendarWeekdayDay(row.bookedOn, i18n.language)}`
      : t('transactions.bulk.unknownTransaction');
  }

  if (skipped.length === 0) return null;
  return (
    <section>
      <h3 className={styles.skippedTitle}>
        {t('transactions.bulk.skipped', { count: skipped.length })}
      </h3>
      <ul className={styles.reasons}>
        {[...byReason].map(([reason, ids]) => (
          <li key={reason}>
            <details>
              <summary>
                <span>{t(`transactions.bulk.reasons.${reason}`)}</span>
                <strong>{ids.length}</strong>
              </summary>
              <ul className={styles.skippedItems}>
                {ids.map((id) => (
                  <li key={id}>{describe(id)}</li>
                ))}
              </ul>
            </details>
          </li>
        ))}
      </ul>
    </section>
  );
}
