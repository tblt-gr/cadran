import type { Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarDay } from '@/lib/decimal';
import { TransactionAmount } from '@/features/transactions/transaction-list/transaction-amount/TransactionAmount';
import { TransactionCategories } from '@/features/transactions/transaction-list/transaction-categories/TransactionCategories';
import styles from './AccountMovementsTable.module.css';

interface AccountMovementsTableProps {
  transactions: Transaction[];
}

const STATE_TONE = {
  PENDING: 'info',
  BOOKED: 'positive',
  VOIDED: 'warning',
  REJECTED: 'negative',
} as const;

/**
 * This account's own movements, newest first: date, nature, label, signed
 * amount, category and state, with the transfer counterpart named when the
 * row is one leg of a transfer. No row from another account ever reaches
 * this table; the account boundary is enforced by the query that feeds it,
 * not by anything filtered here.
 */
export function AccountMovementsTable({ transactions }: AccountMovementsTableProps) {
  const { i18n, t } = useTranslation();

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accounts.detail.movements.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('transactions.fields.bookedOn')}</th>
              <th scope="col">{t('transactions.fields.nature')}</th>
              <th scope="col">{t('transactions.fields.rawLabel')}</th>
              <th scope="col">{t('transactions.fields.category')}</th>
              <th scope="col">{t('accounts.detail.movements.counterpart')}</th>
              <th scope="col">{t('transactions.fields.amount')}</th>
              <th scope="col">{t('transactions.fields.state')}</th>
            </tr>
          </thead>
          <tbody>
            {transactions.map((transaction) => {
              const transferLinked = transaction.transferId !== null;

              return (
                <tr key={transaction.id}>
                  <td data-label={t('transactions.fields.bookedOn')}>
                    {formatCalendarDay(transaction.bookedOn, i18n.language)}
                  </td>
                  <td data-label={t('transactions.fields.nature')}>
                    {t(`transactions.natures.${transaction.nature}`)}
                  </td>
                  <th data-label={t('transactions.fields.rawLabel')} scope="row">
                    {transaction.rawLabel}
                  </th>
                  <td data-label={t('transactions.fields.category')}>
                    <TransactionCategories splits={transaction.splits} />
                  </td>
                  <td data-label={t('accounts.detail.movements.counterpart')}>
                    {transferLinked
                      ? (transaction.counterparty ?? t('transactions.list.transferMarker'))
                      : (transaction.counterparty ?? t('transactions.list.noCounterparty'))}
                  </td>
                  <td data-label={t('transactions.fields.amount')}>
                    <TransactionAmount amount={transaction.amount} />
                  </td>
                  <td data-label={t('transactions.fields.state')}>
                    <StatusBadge tone={STATE_TONE[transaction.state]}>
                      {t(`transactions.states.${transaction.state}`)}
                    </StatusBadge>
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
