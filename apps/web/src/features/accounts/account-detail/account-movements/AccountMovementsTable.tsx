import type { Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarDay } from '@/lib/decimal';
import { TransactionAmount } from '@/features/transactions/transaction-list/transaction-amount/TransactionAmount';
import { TransactionCategories } from '@/features/transactions/transaction-list/transaction-categories/TransactionCategories';
import {
  TransactionInspectButton,
  TransactionRowActions,
} from '@/features/transactions/transaction-workflow/transaction-row-actions/TransactionRowActions';
import styles from './AccountMovementsTable.module.css';

const STATE_TONE = {
  PENDING: 'info',
  BOOKED: 'positive',
  VOIDED: 'warning',
  REJECTED: 'negative',
} as const;

/** Nullable rows keep the loading table an element-for-element mirror of the loaded table. */
export function AccountMovementsTable({
  transactions,
  announce = true,
}: {
  transactions: Transaction[] | null;
  announce?: boolean;
}) {
  const { i18n, t } = useTranslation();
  const labels = [
    t('transactions.fields.bookedOn'),
    t('transactions.fields.nature'),
    t('transactions.fields.label'),
    t('transactions.fields.category'),
    t('accounts.detail.movements.counterpart'),
    t('transactions.fields.amount'),
    t('transactions.fields.state'),
    t('transactions.list.actions'),
  ];
  const rows: Array<Transaction | null> = transactions ?? Array.from({ length: 5 }, () => null);
  return (
    <div
      className={`card ${styles.panel}`}
      aria-busy={transactions === null || undefined}
      data-testid={transactions === null ? 'account-movements-skeleton' : undefined}
    >
      {transactions === null && announce ? (
        <SkeletonStatus>{t('transactions.loading')}</SkeletonStatus>
      ) : null}
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accounts.detail.movements.caption')}</caption>
          <thead>
            <tr>
              {labels.map((label, index) => (
                <th scope="col" key={label}>
                  {index === 7 ? <span className="sr-only">{label}</span> : label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((transaction, index) => (
              <tr key={transaction?.id ?? index}>
                <td data-label={labels[0]}>
                  {transaction ? (
                    formatCalendarDay(transaction.bookedOn, i18n.language)
                  ) : (
                    <SkeletonBlock />
                  )}
                </td>
                <td data-label={labels[1]}>
                  {transaction ? (
                    t(`transactions.natures.${transaction.nature}`)
                  ) : (
                    <SkeletonBlock />
                  )}
                </td>
                <th data-label={labels[2]} scope="row">
                  <TransactionInspectButton transaction={transaction} />
                </th>
                <td data-label={labels[3]}>
                  {transaction ? (
                    <TransactionCategories splits={transaction.splits} />
                  ) : (
                    <SkeletonBlock />
                  )}
                </td>
                <td data-label={labels[4]}>
                  {transaction ? (
                    (transaction.counterparty ??
                    t(
                      transaction.transferId !== null
                        ? 'transactions.list.transferMarker'
                        : 'transactions.list.noCounterparty',
                    ))
                  ) : (
                    <SkeletonBlock />
                  )}
                </td>
                <td data-label={labels[5]}>
                  {transaction ? (
                    <TransactionAmount amount={transaction.amount} />
                  ) : (
                    <SkeletonBlock />
                  )}
                </td>
                <td data-label={labels[6]}>
                  <StatusBadge tone={transaction ? STATE_TONE[transaction.state] : 'positive'}>
                    {transaction ? (
                      t(`transactions.states.${transaction.state}`)
                    ) : (
                      <SkeletonBlock />
                    )}
                  </StatusBadge>
                </td>
                <td data-label={labels[7]}>
                  <TransactionRowActions transaction={transaction} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
