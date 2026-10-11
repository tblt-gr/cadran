import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './AccountDetailActions.module.css';

function navigateTo(href: string) {
  window.history.pushState({}, '', href);
  window.dispatchEvent(new PopStateEvent('popstate'));
}

/**
 * Account-level actions, rendered in the shell header: one primary action,
 * frequent actions as icon buttons and portfolio shortcuts in an overflow menu.
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
  const valuedByPortfolio =
    (account.kind === 'PORTFOLIO' || account.kind === 'INSURANCE_CONTRACT') &&
    account.valuationMode === 'PORTFOLIO';

  return (
    <HeaderActions>
      {valuedByPortfolio ? (
        <ActionMenu
          items={[
            {
              icon: 'investments',
              id: 'open-portfolio',
              label: t('accounts.detail.actions.openPortfolio'),
              onSelect: () => navigateTo(`/portfolios/${account.id}`),
              text: t('accounts.detail.actions.openPortfolio'),
            },
            {
              icon: 'balance',
              id: 'value-portfolio',
              label: t('accounts.detail.actions.valuePortfolio'),
              onSelect: () => navigateTo(`/portfolios/${account.id}?tab=valuations`),
              text: t('accounts.detail.actions.valuePortfolio'),
            },
            {
              icon: 'rules',
              id: 'rules',
              label: t('accounts.detail.actions.rules'),
              onSelect: onOpenRules,
              text: t('accounts.detail.actions.rules'),
            },
          ]}
          label={t('accounts.detail.actions.more')}
        />
      ) : (
        <>
          <button
            aria-label={t('accounts.detail.actions.rules')}
            className="icon-button"
            onClick={onOpenRules}
            title={t('accounts.detail.actions.rules')}
            type="button"
          >
            <Icon name="rules" />
          </button>
          <button
            aria-label={t('accounts.detail.actions.reconcile')}
            className="icon-button"
            disabled={account.valuation.snapshotId === null}
            onClick={onReconcile}
            title={t('accounts.detail.actions.reconcile')}
            type="button"
          >
            <Icon name="balance" />
          </button>
        </>
      )}
      <button
        aria-label={t('accounts.detail.actions.addTransfer')}
        className="icon-button"
        disabled={!creatable}
        onClick={onAddTransfer}
        title={t('accounts.detail.actions.addTransfer')}
        type="button"
      >
        <Icon name="transactions" />
      </button>
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
    </HeaderActions>
  );
}

/** Same chrome as the loaded actions, disabled until the account is known. */
export function AccountDetailActionsSkeleton() {
  const { t } = useTranslation();

  return (
    <HeaderActions>
      <button
        aria-label={t('accounts.detail.actions.rules')}
        className="icon-button"
        disabled
        title={t('accounts.detail.actions.rules')}
        type="button"
      >
        <Icon name="rules" />
      </button>
      <button
        aria-label={t('accounts.detail.actions.reconcile')}
        className="icon-button"
        disabled
        title={t('accounts.detail.actions.reconcile')}
        type="button"
      >
        <Icon name="balance" />
      </button>
      <button
        aria-label={t('accounts.detail.actions.addTransfer')}
        className="icon-button"
        disabled
        title={t('accounts.detail.actions.addTransfer')}
        type="button"
      >
        <Icon name="transactions" />
      </button>
      <button
        aria-label={t('accounts.detail.actions.addTransaction')}
        className={`primary-action ${styles.add}`}
        disabled
        type="button"
      >
        <Icon name="add" size={18} />
        <span className={styles.addLabel}>{t('accounts.detail.actions.addTransaction')}</span>
      </button>
    </HeaderActions>
  );
}
