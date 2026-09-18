import type { Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './PendingTransactionsTable.module.css';

interface PendingTransactionsTableProps {
  count: number;
  transactions: Transaction[];
}

/**
 * Pending rows of the period. They are listed because they may explain the
 * discrepancy, but the server never sums them into it.
 */
export function PendingTransactionsTable({ count, transactions }: PendingTransactionsTableProps) {
  const { i18n, t } = useTranslation();

  if (count === 0) {
    return <p className={styles.empty}>{t('accounts.reconciliation.pending.empty')}</p>;
  }

  return (
    <div className={styles.block}>
      <p className={styles.count}>{t('accounts.reconciliation.pending.count', { count })}</p>
      <table className={styles.table}>
        <caption className="sr-only">{t('accounts.reconciliation.pending.caption')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('accounts.reconciliation.pending.date')}</th>
            <th scope="col">{t('accounts.reconciliation.pending.label')}</th>
            <th scope="col">{t('accounts.reconciliation.pending.amount')}</th>
          </tr>
        </thead>
        <tbody>
          {transactions.map((transaction) => (
            <tr key={transaction.id}>
              <td>{formatCalendarDay(transaction.bookedOn, i18n.language)}</td>
              <td>{transaction.rawLabel}</td>
              <td className={styles.amount}>
                {formatAmount(
                  transaction.amount.value,
                  transaction.amount.assetCode,
                  i18n.language,
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {count > transactions.length ? (
        <p className={styles.count}>
          {t('accounts.reconciliation.pending.truncated', { shown: transactions.length, count })}
        </p>
      ) : null}
    </div>
  );
}
