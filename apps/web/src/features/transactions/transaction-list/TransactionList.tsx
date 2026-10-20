import type { Account, Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import {
  TransactionRowActions,
  TransactionInspectButton,
} from '@/features/transactions/transaction-workflow/transaction-row-actions/TransactionRowActions';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarDay } from '@/lib/decimal';
import { RefundBadge } from '@/features/transactions/refund-editor/refund-badge/RefundBadge';
import { TransactionAmount } from './transaction-amount/TransactionAmount';
import { TransactionCategories } from './transaction-categories/TransactionCategories';
import styles from './TransactionList.module.css';

interface TransactionListProps {
  accounts: Account[];
  transactions: Transaction[];
}

export function TransactionList({ accounts, transactions }: TransactionListProps) {
  const { i18n, t } = useTranslation();

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('transactions.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('transactions.fields.bookedOn')}</th>
              <th scope="col">{t('transactions.fields.rawLabel')}</th>
              <th scope="col">{t('transactions.fields.category')}</th>
              <th scope="col">{t('transactions.fields.account')}</th>
              <th className={styles.amount} scope="col">
                {t('transactions.fields.amount')}
              </th>
              <th scope="col">{t('transactions.fields.state')}</th>
              <th scope="col">
                <span className="sr-only">{t('transactions.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {transactions.map((transaction) => {
              const account = accounts.find((candidate) => candidate.id === transaction.accountId);
              const voided = transaction.state === 'VOIDED';
              const transferLinked = transaction.transferId !== null;

              return (
                <tr key={transaction.id}>
                  <td className={styles.date}>
                    {formatCalendarDay(transaction.bookedOn, i18n.language)}
                  </td>
                  <th className={styles.label} scope="row">
                    <TransactionInspectButton transaction={transaction} />
                    {transferLinked ? (
                      <>
                        <small>
                          {t(
                            transaction.nature === 'FEE'
                              ? 'transactions.list.transferFeeMarker'
                              : 'transactions.list.transferMarker',
                          )}
                        </small>
                        <small className={styles.detail}>
                          {t('transactions.list.transferLocked')}
                        </small>
                      </>
                    ) : null}
                    <RefundBadge transaction={transaction} />
                  </th>
                  <td className={styles.category}>
                    <TransactionCategories splits={transaction.splits} />
                  </td>
                  <td className={styles.account}>
                    {account?.label ?? t('transactions.list.unknownAccount')}
                  </td>
                  <td className={styles.amount}>
                    <TransactionAmount amount={transaction.amount} />
                  </td>
                  <td className={styles.state}>
                    {/* Booked is the normal case: only a state that departs from it is worth a badge. */}
                    {transaction.state === 'BOOKED' ? null : (
                      <StatusBadge
                        tone={
                          voided
                            ? 'warning'
                            : transaction.state === 'REJECTED'
                              ? 'negative'
                              : 'positive'
                        }
                      >
                        {t(`transactions.states.${transaction.state}`)}
                      </StatusBadge>
                    )}
                  </td>
                  <td className={styles.actions}>
                    <TransactionRowActions transaction={transaction} />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
