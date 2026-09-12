import type { Account, Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import { TransactionAmount } from './transaction-amount/TransactionAmount';
import { TransactionCategories } from './transaction-categories/TransactionCategories';
import styles from './TransactionList.module.css';

interface TransactionListProps {
  accounts: Account[];
  duplicatingIds: ReadonlySet<string>;
  onDuplicate: (transaction: Transaction) => void;
  onEdit: (transaction: Transaction) => void;
  onRefund: (transaction: Transaction) => void;
  onVoid: (transaction: Transaction) => void;
  transactions: Transaction[];
}

export function TransactionList({
  accounts,
  duplicatingIds,
  onDuplicate,
  onEdit,
  onRefund,
  onVoid,
  transactions,
}: TransactionListProps) {
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
              <th scope="col">{t('transactions.fields.counterparty')}</th>
              <th scope="col">{t('transactions.fields.category')}</th>
              <th scope="col">{t('transactions.fields.account')}</th>
              <th scope="col">{t('transactions.fields.amount')}</th>
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
              const terminal = voided || transaction.state === 'REJECTED';
              const transferLinked = transaction.transferId !== null;
              const linked = transferLinked || transaction.nature === 'REFUND';
              const refundable =
                transaction.state === 'BOOKED' &&
                !transferLinked &&
                (transaction.nature === 'EXPENSE' || transaction.nature === 'FEE');

              return (
                <tr key={transaction.id}>
                  <td data-label={t('transactions.fields.bookedOn')}>
                    {formatCalendarDay(transaction.bookedOn, i18n.language)}
                  </td>
                  <th data-label={t('transactions.fields.rawLabel')} scope="row">
                    <span>{transaction.rawLabel}</span>
                    {transferLinked ? (
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
                    {transaction.refundOriginalLabel ? (
                      <small>
                        {t('transactions.list.refundOf', {
                          label: transaction.refundOriginalLabel,
                        })}
                      </small>
                    ) : null}
                    {transaction.refundedAmount ? (
                      <small>
                        {t('transactions.list.refundedAmount', {
                          amount: formatAmount(
                            transaction.refundedAmount.value,
                            transaction.refundedAmount.assetCode,
                            i18n.language,
                          ),
                        })}
                      </small>
                    ) : null}
                  </th>
                  <td data-label={t('transactions.fields.counterparty')}>
                    {transaction.counterparty ?? t('transactions.list.noCounterparty')}
                  </td>
                  <td data-label={t('transactions.fields.category')}>
                    <TransactionCategories splits={transaction.splits} />
                  </td>
                  <td data-label={t('transactions.fields.account')}>
                    {account?.label ?? t('transactions.list.unknownAccount')}
                  </td>
                  <td data-label={t('transactions.fields.amount')}>
                    <TransactionAmount amount={transaction.amount} />
                  </td>
                  <td data-label={t('transactions.fields.state')}>
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
                  </td>
                  <td className={styles.actions}>
                    <ActionMenu
                      items={[
                        {
                          disabled: !refundable,
                          icon: 'replace',
                          id: 'refund',
                          label: t('transactions.list.actionFor', {
                            action: t('transactions.list.refund'),
                            label: transaction.rawLabel,
                          }),
                          onSelect: () => onRefund(transaction),
                          text: t('transactions.list.refund'),
                        },
                        {
                          disabled: terminal || linked,
                          icon: 'edit',
                          id: 'edit',
                          label: t('transactions.list.actionFor', {
                            action: t('transactions.list.edit'),
                            label: transaction.rawLabel,
                          }),
                          onSelect: () => onEdit(transaction),
                          text: t('transactions.list.edit'),
                        },
                        {
                          disabled: duplicatingIds.has(transaction.id) || linked,
                          icon: 'copy',
                          id: 'duplicate',
                          label: t('transactions.list.actionFor', {
                            action: t('transactions.list.duplicate'),
                            label: transaction.rawLabel,
                          }),
                          onSelect: () => onDuplicate(transaction),
                          text: t('transactions.list.duplicate'),
                        },
                        {
                          disabled: terminal || transferLinked,
                          icon: 'archive',
                          id: 'void',
                          label: t('transactions.list.actionFor', {
                            action: t('transactions.list.void'),
                            label: transaction.rawLabel,
                          }),
                          onSelect: () => onVoid(transaction),
                          text: t('transactions.list.void'),
                        },
                      ]}
                      label={t('transactions.list.openActions', { label: transaction.rawLabel })}
                    />
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
