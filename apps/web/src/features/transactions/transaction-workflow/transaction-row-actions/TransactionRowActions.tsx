import type { Transaction } from '@cadran/api-client';
import styles from './TransactionRowActions.module.css';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { useContext, type ContextType } from 'react';
import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import {
  TransactionActionsContext,
  transactionEligibility,
  type TransactionAction,
} from '@/features/transactions/transaction-workflow/transactionActions';

export function TransactionRowActions({ transaction }: { transaction: Transaction | null }) {
  const context = useContext(TransactionActionsContext);
  const { t } = useTranslation();
  if (transaction === null)
    return <ActionMenu disabled label={t('transactions.list.actions')} items={[]} />;
  return <LoadedTransactionActions transaction={transaction} context={context} />;
}

function LoadedTransactionActions({
  transaction,
  context,
}: {
  transaction: Transaction;
  context: ContextType<typeof TransactionActionsContext>;
}) {
  const { t } = useTranslation();
  const eligibility = transactionEligibility(
    transaction,
    context?.closedMonths.has(transaction.bookedOn.slice(0, 7)) ?? false,
    context?.accounts.find((a) => a.id === transaction.accountId),
  );
  const portfolioOperationsHref = `/portfolios/${transaction.accountId}?tab=operations`;
  const actions: Array<[TransactionAction, 'search' | 'replace' | 'edit' | 'copy' | 'archive']> = [
    ['inspect', 'search'],
    ['refund', 'replace'],
    ['edit', 'edit'],
    ['duplicate', 'copy'],
    ['void', 'archive'],
    ...(transaction.reviewReason !== null
      ? [['reconcile', 'replace'] as ['reconcile', 'replace']]
      : []),
  ];
  return (
    <>
      <ActionMenu
        label={t('transactions.list.openActions', { label: transaction.rawLabel })}
        items={actions.map(([action, icon]) => ({
          id: action,
          icon,
          text: t(
            action === 'refund' ? 'transactions.list.refund' : `transactions.workflow.${action}`,
          ),
          label:
            action === 'inspect'
              ? t('transactions.workflow.inspectFor', { label: transaction.rawLabel })
              : t('transactions.list.actionFor', {
                  action: t(
                    action === 'refund'
                      ? 'transactions.list.refund'
                      : `transactions.workflow.${action}`,
                  ),
                  label: transaction.rawLabel,
                }),
          disabled:
            !context ||
            (action !== 'inspect' && !eligibility[action]) ||
            (action === 'duplicate' && context.duplicatingIds.has(transaction.id)),
          onSelect: () => context?.open(action, transaction),
        }))}
      />
      {eligibility.reason === 'portfolio' ? (
        <a
          href={portfolioOperationsHref}
          onClick={(event) => handleClientNavigation(event, portfolioOperationsHref)}
        >
          {t('transactions.workflow.portfolioLink')}
        </a>
      ) : null}
      {eligibility.reason ? (
        <small>{t(`transactions.workflow.locked.${eligibility.reason}`)}</small>
      ) : null}
    </>
  );
}

export function TransactionInspectButton({ transaction }: { transaction: Transaction | null }) {
  const context = useContext(TransactionActionsContext);
  const { t } = useTranslation();
  return (
    <button
      className={styles.inspect}
      type="button"
      disabled={!context || transaction === null}
      aria-label={
        transaction
          ? t('transactions.workflow.inspectFor', { label: transaction.rawLabel })
          : t('transactions.workflow.inspect')
      }
      onClick={() => transaction && context?.open('inspect', transaction)}
    >
      {transaction?.rawLabel ?? <SkeletonBlock />}
    </button>
  );
}
