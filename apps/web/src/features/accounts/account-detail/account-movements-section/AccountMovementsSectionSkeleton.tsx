import { formatCalendarMonth } from '@/lib/decimal';
import styles from './AccountMovementsSection.module.css';
import { useTranslation } from 'react-i18next';
import { BulkSelectionToolbar } from '@/features/transactions/bulk-selection/BulkSelectionToolbar';
import { TransactionDayList } from '@/features/transactions/transaction-list/transaction-day-list/TransactionDayList';

/** Mirrors AccountMovementsSection while its movements load, before the account is known. */
export function AccountMovementsSectionSkeleton({
  month = null,
  showBulkToolbar = true,
}: {
  month?: string | null;
  showBulkToolbar?: boolean;
}) {
  const { t, i18n } = useTranslation();
  return (
    <>
      <h3 className="sr-only">{t('accounts.detail.movements.title')}</h3>
      {month ? (
        <p className={styles.periodNotice}>
          {t('accounts.detail.movements.periodActive', {
            month: formatCalendarMonth(`${month}-01`, i18n.language),
          })}
          <button className="secondary-action" disabled type="button">
            {t('accounts.detail.movements.clearPeriod')}
          </button>
        </p>
      ) : null}
      {showBulkToolbar ? (
        <BulkSelectionToolbar active={false} bulk={null} onToggleActive={() => {}} />
      ) : null}
      <TransactionDayList
        announce={false}
        headingLevel={4}
        label={t('accounts.detail.movements.caption')}
        transactions={null}
      />
    </>
  );
}
