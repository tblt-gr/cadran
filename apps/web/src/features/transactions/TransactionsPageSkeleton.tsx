import { SkeletonButton } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import { BulkSelectionToolbar } from './bulk-selection/BulkSelectionToolbar';
import { NO_ROW_SELECTION } from './bulk-selection/useBulkSelection';
import { TransactionDayList } from './transaction-list/transaction-day-list/TransactionDayList';
import footerStyles from './transaction-list/TransactionListFooter.module.css';

/** Mirrors the day list and its footer: the list mirrors itself through its nullable rows. */
export function TransactionsPageSkeleton() {
  const { t } = useTranslation();
  return (
    <>
      <BulkSelectionToolbar bulk={null} />
      <TransactionDayList
        accounts={[]}
        label={t('transactions.list.caption')}
        selection={NO_ROW_SELECTION}
        transactions={null}
      />
      <div aria-hidden="true" className={footerStyles.footer}>
        <SkeletonButton />
      </div>
    </>
  );
}
