import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { AccountActionsMenu } from '@/features/accounts/account-actions-menu/AccountActionsMenu';
import styles from './AccountDetailActions.module.css';

/**
 * Account-level actions, rendered in the shell header: the primary action and the
 * shared three-dot menu, last on the right. A portfolio account never shows them:
 * its page is the portfolio page.
 */
export function AccountDetailActions({
  account,
  balanceTab = false,
  creatable,
  onAddTransaction,
  onAddTransfer,
  onOpenRules,
  onReconcile,
}: {
  account: Account;
  /** The Solde tab is shown: recording a balance is the primary action. */
  balanceTab?: boolean;
  creatable: boolean;
  onAddTransaction: () => void;
  onAddTransfer: () => void;
  onOpenRules: () => void;
  onReconcile: () => void;
}) {
  const { t } = useTranslation();
  const writable = creatable && account.editable;

  return (
    <HeaderActions>
      <AccountActionsMenu
        account={account}
        onReconcile={onReconcile}
        onRules={onOpenRules}
        leading={({ recordBalance }) => (
          <>
            <button
              aria-label={t(
                balanceTab
                  ? 'accounts.detail.actionsMenu.recordBalance'
                  : 'accounts.detail.actions.addTransaction',
              )}
              className={`primary-action ${styles.add}`}
              disabled={balanceTab ? !writable : !creatable}
              onClick={balanceTab ? recordBalance : onAddTransaction}
              type="button"
            >
              <Icon name={balanceTab ? 'balance' : 'add'} size={18} />
              <span className={styles.addLabel}>
                {t(
                  balanceTab
                    ? 'accounts.detail.actionsMenu.recordBalance'
                    : 'accounts.detail.actions.addTransaction',
                )}
              </span>
            </button>
            <button
              aria-label={t('accounts.detail.actionsMenu.transfer')}
              className={`secondary-action ${styles.add}`}
              disabled={!writable}
              onClick={onAddTransfer}
              type="button"
            >
              <Icon name="transfer" size={18} />
              <span className={styles.addLabel}>{t('accounts.detail.actionsMenu.transfer')}</span>
            </button>
          </>
        )}
      />
    </HeaderActions>
  );
}

/** Same chrome as the loaded actions, disabled until the account is known. */
export function AccountDetailActionsSkeleton() {
  const { t } = useTranslation();

  return (
    <HeaderActions>
      <button
        aria-label={t('accounts.detail.actions.addTransaction')}
        className={`primary-action ${styles.add}`}
        disabled
        type="button"
      >
        <Icon name="add" size={18} />
        <span className={styles.addLabel}>{t('accounts.detail.actions.addTransaction')}</span>
      </button>
      <button
        aria-label={t('accounts.detail.actionsMenu.transfer')}
        className={`secondary-action ${styles.add}`}
        disabled
        type="button"
      >
        <Icon name="transfer" size={18} />
        <span className={styles.addLabel}>{t('accounts.detail.actionsMenu.transfer')}</span>
      </button>
      <AccountActionsMenu onRules={() => undefined} />
    </HeaderActions>
  );
}
