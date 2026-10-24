import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { TransactionAmount } from '@/features/transactions/transaction-list/transaction-amount/TransactionAmount';
import type { DayTotal } from './groupTransactionsByDay';
import styles from './TransactionDayList.module.css';

interface TransactionDayTotalProps {
  /** A search or filter narrows the rows: the net is then only that of the rows shown. */
  filtered: boolean;
  /** Null stands for a total still loading. */
  total: DayTotal | null;
}

/** The day net in its header. Every state keeps the same two-element shape as the skeleton. */
export function TransactionDayTotal({ filtered, total }: TransactionDayTotalProps) {
  const { t } = useTranslation();

  return (
    <span className={styles.total}>
      <span className={filtered ? styles.filteredLabel : 'sr-only'}>
        {t(filtered ? 'transactions.day.totalFiltered' : 'transactions.day.total')}
      </span>
      <span className={styles.value} data-kind={total?.kind}>
        {total === null ? (
          <SkeletonBlock />
        ) : total.kind === 'amount' ? (
          <TransactionAmount amount={total.amount} />
        ) : (
          t(`transactions.day.${total.kind}`)
        )}
      </span>
    </span>
  );
}
