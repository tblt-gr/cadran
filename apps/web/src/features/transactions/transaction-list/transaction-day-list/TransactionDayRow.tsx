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
import { formatCalendarShortDay } from '@/lib/decimal';
import styles from './TransactionDayList.module.css';

const STATE_TONE = {
  PENDING: 'info',
  VOIDED: 'warning',
  REJECTED: 'negative',
} as const;

interface TransactionDayRowProps {
  accounts: Account[] | undefined;
  /** Adds the selection checkbox column; rows still loading show it disabled. */
  selection?: RowSelection;
  /** Null while loading: the row keeps its final structure and shows blocks for the values. */
  transaction: Transaction | null;
  counterpartAccount?: string;
}

/** Stable register columns on desktop, compact identity and amount on a phone. */
export function TransactionDayRow({
  accounts,
  selection,
  transaction,
  counterpartAccount,
}: TransactionDayRowProps) {
  const { i18n, t } = useTranslation();
  // Muted, never hidden: the state badge keeps the cue that colour alone would not carry.
  const pending = transaction?.state === 'PENDING';
  // A planned row is booked by the server on its date: say so in words, not by the grey alone.
  const planned = pending && transaction.autoBook;
  const account = accounts?.find((candidate) => candidate.id === transaction?.accountId);

  return (
    <li
      className={[
        styles.row,
        selection ? styles.selectable : '',
        pending ? styles.pending : '',
        planned ? styles.planned : '',
      ]
        .filter(Boolean)
        .join(' ')}
      data-transfer-id={transaction?.transferId ?? undefined}
      data-state={transaction?.state}
      data-accounts={accounts !== undefined}
    >
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
          <small>
            {t(
              transaction.nature === 'FEE'
                ? 'transactions.list.transferFeeMarker'
                : 'transactions.list.transferMarker',
            )}
            {counterpartAccount ? ` · ${counterpartAccount}` : null}
          </small>
        ) : null}
        {transaction ? <RefundBadge transaction={transaction} /> : null}
        {/* Booked is the normal case: only a state that departs from it is shown. */}
        {transaction && transaction.state !== 'BOOKED' ? (
          <span className={styles.state}>
            <StatusBadge tone={STATE_TONE[transaction.state]}>
              {planned
                ? t('transactions.states.planned', {
                    date: formatCalendarShortDay(transaction.bookedOn, i18n.language),
                  })
                : t(`transactions.states.${transaction.state}`)}
            </StatusBadge>
          </span>
        ) : null}
      </div>
      <span className={styles.amount}>
        {transaction ? <TransactionAmount amount={transaction.amount} /> : <SkeletonBlock />}
      </span>
      <div className={styles.meta}>
        {/* A transfer is neutral: its category cell exists only when one was set. */}
        {transaction?.transferId != null && transaction.splits.length === 0 ? null : (
          <span className={styles.category}>
            {transaction ? (
              <TransactionCategories splits={transaction.splits} />
            ) : (
              <SkeletonBlock />
            )}
          </span>
        )}
        {accounts ? (
          <span className={styles.account} title={account?.label}>
            {transaction ? (
              (account?.label ?? t('transactions.list.unknownAccount'))
            ) : (
              <SkeletonBlock />
            )}
          </span>
        ) : null}
      </div>
      <div className={styles.actions}>
        <TransactionRowActions transaction={transaction} />
      </div>
    </li>
  );
}
