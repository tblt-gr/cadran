import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { BulkEditResult, BulkSkipReason } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatCalendarWeekdayDay } from '@/lib/decimal';
import type { BulkSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import styles from './BulkEditModal.module.css';

interface BulkEditResultViewProps {
  onDone: () => void;
  pageItems: BulkSelection['pageItems'];
  result: BulkEditResult;
}

/** What a bulk edit did: how many were written, and every skipped one under its reason. */
export function BulkEditResultView({ onDone, pageItems, result }: BulkEditResultViewProps) {
  const { i18n, t } = useTranslation();
  const byReason = new Map<BulkSkipReason, string[]>();
  for (const skipped of result.skipped) {
    byReason.set(skipped.reason, [...(byReason.get(skipped.reason) ?? []), skipped.id]);
  }

  function describe(id: string): string {
    const row = pageItems.find((item) => item.id === id);
    return row
      ? `${row.displayLabel}, ${formatCalendarWeekdayDay(row.bookedOn, i18n.language)}`
      : t('transactions.bulk.unknownTransaction');
  }

  return (
    <div className={styles.form}>
      <p role="status">{t('transactions.bulk.changed', { count: result.changed })}</p>
      {result.skipped.length > 0 ? (
        <section>
          <h3 className={styles.skippedTitle}>
            {t('transactions.bulk.skipped', { count: result.skipped.length })}
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
      ) : null}
      <ModalActions>
        <button className="primary-action" onClick={onDone} type="button">
          {t('transactions.bulk.done')}
        </button>
      </ModalActions>
    </div>
  );
}
