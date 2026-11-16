import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import {
  accountActionHref,
  accountMenuItems,
  INSTRUMENTS_HREF,
} from '@/features/accounts/account-actions-menu/accountMenuItems';
import { navigateFromPage } from '@/hooks/use-client-navigation';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';

interface AccountRowMenuProps {
  account: Account;
  onArchive: (account: Account) => void;
  onEdit: (account: Account) => void;
  onReconcile: (account: Account) => void;
  onRecordBalance: (account: Account) => void;
  onRules: (account: Account) => void;
}

/** The action menu of one account row: page shortcuts navigate, the rest ask the page for a modal. */
export function AccountRowMenu({
  account,
  onArchive,
  onEdit,
  onReconcile,
  onRecordBalance,
  onRules,
}: AccountRowMenuProps) {
  const { t } = useTranslation();
  const { label } = account;

  return (
    <ActionMenu
      items={accountMenuItems({
        account,
        handlers: {
          onAddOperation: () =>
            navigateFromPage(
              accountActionHref(account, valuedByPortfolio(account) ? 'operation' : 'transaction'),
            ),
          onAddTransfer: () => navigateFromPage(accountActionHref(account, 'transfer')),
          onArchive: () => onArchive(account),
          onEdit: () => onEdit(account),
          onInstruments: () => navigateFromPage(INSTRUMENTS_HREF),
          onReconcile: () => onReconcile(account),
          onRecordBalance: () => onRecordBalance(account),
          onRecordValuation: () => navigateFromPage(accountActionHref(account, 'valuation')),
          onRules: () => onRules(account),
        },
        names: {
          archive: t('accounts.list.archiveAccount', {
            label: label,
          }),
          edit: t('accounts.list.editAccount', { label: label }),
          instruments: t('accounts.list.instrumentsOfAccount', {
            label: label,
          }),
          reconcile: t('accounts.list.reconcileOfAccount', {
            label: label,
          }),
          operation: t('accounts.list.operationOfAccount', {
            label: label,
          }),
          recordBalance: t('accounts.list.recordBalanceOfAccount', {
            label: label,
          }),
          rules: t('accounts.list.rulesOfAccount', { label: label }),
          transfer: t('accounts.list.transferOfAccount', {
            label: label,
          }),
          valuation: t('accounts.list.valuationOfAccount', {
            label: label,
          }),
        },
        t,
      })}
      label={t('accounts.list.openActions', { label: label })}
    />
  );
}
