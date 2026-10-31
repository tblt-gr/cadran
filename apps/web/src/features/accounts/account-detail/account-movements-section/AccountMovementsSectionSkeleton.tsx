import { useTranslation } from 'react-i18next';
import { BulkSelectionToolbar } from '@/features/transactions/bulk-selection/BulkSelectionToolbar';
import { TransactionDayList } from '@/features/transactions/transaction-list/transaction-day-list/TransactionDayList';
import styles from './AccountMovementsSection.module.css';

/** Mirrors AccountMovementsSection while its movements load, before the account is known. */
export function AccountMovementsSectionSkeleton() {
  const { t } = useTranslation();
  return (
    <>
      <div className={styles.movementsHeading}>
        <h3 className={styles.movementsTitle}>{t('accounts.detail.movements.title')}</h3>
      </div>
      <BulkSelectionToolbar active={false} bulk={null} onToggleActive={() => {}} />
      <TransactionDayList
        announce={false}
        headingLevel={4}
        label={t('accounts.detail.movements.caption')}
        transactions={null}
      />
    </>
  );
}
