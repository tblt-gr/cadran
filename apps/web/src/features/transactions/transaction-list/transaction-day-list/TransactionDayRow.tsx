import type { Account, Transaction } from '@cadran/api-client';
import { useContext } from 'react';
import { useTranslation } from 'react-i18next';
import { DatedCell } from '@/components/ui/dated-list/DatedCell';
import { DatedRow } from '@/components/ui/dated-list/DatedRow';
import { DatedRowCheckbox } from '@/components/ui/dated-list/DatedRowCheckbox';
import type { DatedRowSelection } from '@/components/ui/dated-list/datedListTypes';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { RefundBadge } from '@/features/transactions/refund-editor/refund-badge/RefundBadge';
import {
  TransactionInspectButton,
  TransactionRowActions,
} from '@/features/transactions/transaction-workflow/transaction-row-actions/TransactionRowActions';
import { TransactionAmount } from '@/features/transactions/transaction-list/transaction-amount/TransactionAmount';
import { TransactionCategories } from '@/features/transactions/transaction-list/transaction-categories/TransactionCategories';
import { TransactionCategoryMark } from '@/features/transactions/transaction-list/transaction-categories/TransactionCategoryMark';
import { TransactionActionsContext } from '@/features/transactions/transaction-workflow/transactionActions';
import { formatCalendarShortDay } from '@/lib/decimal';
import styles from './TransactionDayRow.module.css';

const STATE_TONE = {
  VOIDED: 'warning',
  REJECTED: 'negative',
} as const;

interface TransactionDayRowProps {
  accounts: Account[] | undefined;
  /** Adds the selection checkbox column; rows still loading show it disabled. */
  selection?: DatedRowSelection;
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
  const actions = useContext(TransactionActionsContext);
  // Muted, never hidden: the state badge keeps the cue that colour alone would not carry.
  const pending = transaction?.state === 'PENDING';
  // A planned row is booked by the server on its date: say so in words, not by the grey alone.
  const planned = pending && transaction.autoBook;
  const account = accounts?.find((candidate) => candidate.id === transaction?.accountId);
  const stateText = planned
    ? t('transactions.states.planned', {
        date: formatCalendarShortDay(transaction.bookedOn, i18n.language),
      })
    : t('transactions.states.PENDING');

  function transferNote(leg: Transaction) {
    if (leg.nature === 'FEE') {
      return (
        <>
          {t('transactions.list.transferFeeMarker')}
          {counterpartAccount ? ` · ${counterpartAccount}` : null}
        </>
      );
    }
    if (!counterpartAccount || !account) return t('transactions.list.transferMarker');
    // The leg that leaves the account is the source; the one that enters is the destination.
    return leg.amount.value.startsWith('-')
      ? t('transactions.list.transferRoute', { from: account.label, to: counterpartAccount })
      : t('transactions.list.transferRoute', { from: counterpartAccount, to: account.label });
  }

  return (
    <DatedRow
      className={[pending ? styles.pending : '', planned ? styles.planned : '']
        .filter(Boolean)
        .join(' ')}
      dataAttributes={{
        'data-accounts': accounts !== undefined,
        'data-state': transaction?.state,
        'data-transfer-id': transaction?.transferId ?? undefined,
      }}
      leading={
        selection ? (
          <DatedRowCheckbox
            checked={selection.selected}
            disabled={selection.disabled}
            label={
              transaction
                ? t('transactions.bulk.selectRow', { label: transaction.displayLabel })
                : t('transactions.bulk.selectRowLoading')
            }
            onToggle={selection.toggle}
          />
        ) : undefined
      }
      // The whole row opens the detail dialog; the label button stays the keyboard and screen
      // reader way in.
      onOpen={transaction && actions ? () => actions.open('inspect', transaction) : undefined}
      state={
        pending
          ? {
              // A planned row is booked by the server on its date: say so in words, not by the grey alone.
              tone: planned ? 'muted' : 'pending',
              cue: (
                <span aria-label={stateText} className={styles.clock} role="img" title={stateText}>
                  <Icon name="clock" size={14} />
                </span>
              ),
            }
          : undefined
      }
      title={
        <>
          <span className={styles.phoneOnly}>
            {transaction ? (
              <TransactionCategoryMark
                splits={transaction.splits}
                transfer={transaction.transferId != null}
              />
            ) : null}
          </span>
          <TransactionInspectButton transaction={transaction} />
        </>
      }
      meta={
        <>
          {transaction?.transferId != null ? (
            <small className={styles.note}>{transferNote(transaction)}</small>
          ) : null}
          {transaction ? <RefundBadge transaction={transaction} /> : null}
          {/* Booked is the normal case, pending is the clock next to the label: only the other
              states that depart from it get a badge. */}
          {transaction && (transaction.state === 'VOIDED' || transaction.state === 'REJECTED') ? (
            <span className={styles.state}>
              <StatusBadge tone={STATE_TONE[transaction.state]}>
                {t(`transactions.states.${transaction.state}`)}
              </StatusBadge>
            </span>
          ) : null}
        </>
      }
      cells={
        <>
          {/* A transfer is neutral: its category cell stays empty unless one was set. */}
          <DatedCell className={styles.category} column={1}>
            {transaction?.transferId != null &&
            transaction.splits.length === 0 ? null : transaction ? (
              <TransactionCategories splits={transaction.splits} />
            ) : (
              <SkeletonBlock />
            )}
          </DatedCell>
          {accounts ? (
            <DatedCell className={styles.account} column={2} title={account?.label}>
              {transaction ? (
                (account?.label ?? t('transactions.list.unknownAccount'))
              ) : (
                <SkeletonBlock />
              )}
            </DatedCell>
          ) : null}
        </>
      }
      value={transaction ? <TransactionAmount amount={transaction.amount} /> : <SkeletonBlock />}
      menu={<TransactionRowActions transaction={transaction} />}
    />
  );
}
