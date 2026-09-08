import type { Account, Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarDay } from '@/lib/decimal';
import { TransactionAmount } from './transaction-amount/TransactionAmount';
import styles from './TransactionList.module.css';

interface TransactionListProps {
  accounts: Account[];
  onDuplicate: (transaction: Transaction) => void;
  onEdit: (transaction: Transaction) => void;
  onVoid: (transaction: Transaction) => void;
  transactions: Transaction[];
}

export function TransactionList({
  accounts,
  onDuplicate,
  onEdit,
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

              return (
                <tr key={transaction.id}>
                  <td>{formatCalendarDay(transaction.bookedOn, i18n.language)}</td>
                  <th scope="row">
                    <span>{transaction.rawLabel}</span>
                    {transaction.splits[0] ? (
                      <small>{t('transactions.list.categorised')}</small>
                    ) : (
                      <small>{t('transactions.list.toCategorise')}</small>
                    )}
                  </th>
                  <td>{transaction.counterparty ?? t('transactions.list.noCounterparty')}</td>
                  <td>{account?.label ?? t('transactions.list.unknownAccount')}</td>
                  <td>
                    <TransactionAmount amount={transaction.amount} />
                  </td>
                  <td>
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
                  <td>
                    <ActionMenu
                      items={[
                        {
                          disabled: terminal,
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
                          disabled:
                            transaction.nature === 'TRANSFER' || transaction.nature === 'REFUND',
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
                          disabled: terminal,
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
