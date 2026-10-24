import { SkeletonButton } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import { TransactionDayList } from './transaction-list/transaction-day-list/TransactionDayList';
import footerStyles from './transaction-list/TransactionListFooter.module.css';

/** Mirrors the day list and its footer: the list mirrors itself through its nullable rows. */
export function TransactionsPageSkeleton() {
  const { t } = useTranslation();
  return (
    <>
      <TransactionDayList
        accounts={[]}
        label={t('transactions.list.caption')}
        transactions={null}
      />
      <div aria-hidden="true" className={footerStyles.footer}>
        <SkeletonButton />
      </div>
    </>
  );
}
