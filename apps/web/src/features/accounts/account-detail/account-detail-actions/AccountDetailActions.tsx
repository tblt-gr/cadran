import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './AccountDetailActions.module.css';

/**
 * Account-level actions, rendered in the shell header: one primary action and
 * frequent actions as icon buttons. A portfolio account never shows them: its
 * page is the portfolio page.
 */
export function AccountDetailActions({
  account,
  creatable,
  onAddTransaction,
  onAddTransfer,
  onOpenRules,
  onReconcile,
}: {
  account: Account;
  creatable: boolean;
  onAddTransaction: () => void;
  onAddTransfer: () => void;
  onOpenRules: () => void;
  onReconcile: () => void;
}) {
  const { t } = useTranslation();

  return (
    <HeaderActions>
      <button
        aria-label={t('accounts.detail.actions.addTransaction')}
        className={`primary-action ${styles.add}`}
        disabled={!creatable}
        onClick={onAddTransaction}
        type="button"
      >
        <Icon name="add" size={18} />
        <span className={styles.addLabel}>{t('accounts.detail.actions.addTransaction')}</span>
      </button>
      <button
        aria-label={t('accounts.detail.actions.addTransfer')}
        className="icon-button"
        disabled={!creatable}
        onClick={onAddTransfer}
        title={t('accounts.detail.actions.addTransfer')}
        type="button"
      >
        <Icon name="transfer" />
      </button>
      <button
        aria-label={t('accounts.detail.actions.rules')}
        className="icon-button"
        onClick={onOpenRules}
        title={t('accounts.detail.actions.rules')}
        type="button"
      >
        <Icon name="sliders" />
      </button>
      <button
        aria-label={t('accounts.detail.actions.reconcile')}
        className="icon-button"
        disabled={account.valuation.snapshotId === null}
        onClick={onReconcile}
        title={t('accounts.detail.actions.reconcile')}
        type="button"
      >
        <Icon name="check-circle" />
      </button>
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
        aria-label={t('accounts.detail.actions.addTransfer')}
        className="icon-button"
        disabled
        title={t('accounts.detail.actions.addTransfer')}
        type="button"
      >
        <Icon name="transfer" />
      </button>
      <button
        aria-label={t('accounts.detail.actions.rules')}
        className="icon-button"
        disabled
        title={t('accounts.detail.actions.rules')}
        type="button"
      >
        <Icon name="sliders" />
      </button>
      <button
        aria-label={t('accounts.detail.actions.reconcile')}
        className="icon-button"
        disabled
        title={t('accounts.detail.actions.reconcile')}
        type="button"
      >
        <Icon name="check-circle" />
      </button>
    </HeaderActions>
  );
}
