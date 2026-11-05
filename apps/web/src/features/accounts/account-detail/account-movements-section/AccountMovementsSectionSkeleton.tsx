import { useTranslation } from 'react-i18next';
import { BulkSelectionToolbar } from '@/features/transactions/bulk-selection/BulkSelectionToolbar';
import { TransactionDayList } from '@/features/transactions/transaction-list/transaction-day-list/TransactionDayList';

/** Mirrors AccountMovementsSection while its movements load, before the account is known. */
export function AccountMovementsSectionSkeleton() {
  const { t } = useTranslation();
  return (
    <>
      <h3 className="sr-only">{t('accounts.detail.movements.title')}</h3>
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
