import type { Account, Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { RefundBadge } from '@/features/transactions/refund-editor/refund-badge/RefundBadge';
import {
  TransactionInspectButton,
  TransactionRowActions,
} from '@/features/transactions/transaction-workflow/transaction-row-actions/TransactionRowActions';
import { TransactionAmount } from '@/features/transactions/transaction-list/transaction-amount/TransactionAmount';
import { TransactionCategories } from '@/features/transactions/transaction-list/transaction-categories/TransactionCategories';
import type { RowSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import styles from './TransactionDayList.module.css';

const STATE_TONE = {
  PENDING: 'info',
  BOOKED: 'positive',
  VOIDED: 'warning',
  REJECTED: 'negative',
} as const;

interface TransactionDayRowProps {
  accounts: Account[] | undefined;
  /** Adds the selection checkbox column; rows still loading show it disabled. */
  selection?: RowSelection;
  /** Null while loading: the row keeps its final structure and shows blocks for the values. */
  transaction: Transaction | null;
}

/** One compact movement. The columns of the former table live in the detail modal. */
export function TransactionDayRow({ accounts, selection, transaction }: TransactionDayRowProps) {
  const { t } = useTranslation();
  const account = accounts?.find((candidate) => candidate.id === transaction?.accountId);

  return (
    <li className={selection ? `${styles.row} ${styles.selectable}` : styles.row}>
      {selection ? (
        <label className={styles.select}>
          <input
            aria-label={
              transaction
                ? t('transactions.bulk.selectRow', { label: transaction.displayLabel })
                : t('transactions.bulk.selectRowLoading')
            }
            checked={transaction ? selection.selectedIds.has(transaction.id) : false}
            disabled={transaction === null}
            onChange={() => transaction && selection.onToggle(transaction.id)}
            type="checkbox"
          />
        </label>
      ) : null}
      <div className={styles.primary}>
        <TransactionInspectButton transaction={transaction} />
        {transaction?.transferId != null ? (
          <>
            <small>
              {t(
                transaction.nature === 'FEE'
                  ? 'transactions.list.transferFeeMarker'
                  : 'transactions.list.transferMarker',
              )}
            </small>
            <small>{t('transactions.list.transferLocked')}</small>
          </>
        ) : null}
        {transaction ? <RefundBadge transaction={transaction} /> : null}
      </div>
      <span className={styles.amount}>
        {transaction ? <TransactionAmount amount={transaction.amount} /> : <SkeletonBlock />}
      </span>
      <div className={styles.meta}>
        <span className={styles.category}>
          {transaction ? <TransactionCategories splits={transaction.splits} /> : <SkeletonBlock />}
        </span>
        {accounts ? (
          <span className={styles.account}>
            {transaction ? (
              (account?.label ?? t('transactions.list.unknownAccount'))
            ) : (
              <SkeletonBlock />
            )}
          </span>
        ) : null}
        <span className={styles.state}>
          {/* Booked is the normal case: only a state that departs from it is worth a badge. */}
          {transaction && transaction.state !== 'BOOKED' ? (
            <StatusBadge tone={STATE_TONE[transaction.state]}>
              {t(`transactions.states.${transaction.state}`)}
            </StatusBadge>
          ) : null}
        </span>
      </div>
      <div className={styles.actions}>
        <TransactionRowActions transaction={transaction} />
      </div>
    </li>
  );
}
