import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { MobileHeaderActions } from '@/components/layout/header/MobileHeaderActions';
import { Icon } from '@/components/ui/icon/Icon';
import { AccountActionsMenu } from '@/features/accounts/account-actions-menu/AccountActionsMenu';
import type { AccountTab } from '@/features/accounts/account-detail/AccountNavigation';
import { useMediaQuery } from '@/hooks/use-media-query';

/**
 * Account-level actions, rendered in the shell header, by tab: the Solde tab only records a
 * balance, the Opérations tab offers the operation and the transfer, and the Compte tab adds the
 * three-dot menu (reconcile, rules, edit, archive). A portfolio account never shows them: its page
 * is the portfolio page.
 */
export function AccountDetailActions({
  account,
  tab,
  creatable,
  onAddTransaction,
  onAddTransfer,
  onOpenRules,
  onReconcile,
}: {
  account: Account;
  tab: AccountTab;
  creatable: boolean;
  onAddTransaction: () => void;
  onAddTransfer: () => void;
  onOpenRules: () => void;
  onReconcile: () => void;
}) {
  const { t } = useTranslation();
  const writable = creatable && account.editable;
  const balanceTab = tab === 'balance';
  const phone = useMediaQuery('(max-width: 767px)');
  // On a phone the primary action is an icon and the transfer moves into a menu: the account
  // tab's own three-dot menu on the Compte tab, the page-actions menu on Opérations.
  const transferInAccountMenu = phone && tab === 'account';

  return (
    <HeaderActions>
      <AccountActionsMenu
        account={account}
        onAddTransfer={transferInAccountMenu ? onAddTransfer : undefined}
        onReconcile={onReconcile}
        onRules={onOpenRules}
        showMenu={tab === 'account'}
        leading={({ recordBalance }) => {
          const primary = (
            <button
              aria-label={t(
                balanceTab
                  ? 'accounts.detail.actionsMenu.recordBalance'
                  : 'accounts.detail.actions.addTransaction',
              )}
              className="primary-action"
              disabled={balanceTab ? !writable : !creatable}
              onClick={balanceTab ? recordBalance : onAddTransaction}
              type="button"
            >
              <Icon name={balanceTab ? 'balance' : 'add'} size={18} />
              <span>
                {t(
                  balanceTab
                    ? 'accounts.detail.actionsMenu.recordBalance'
                    : 'accounts.detail.actions.addTransaction',
                )}
              </span>
            </button>
          );
          const transfer = balanceTab ? null : (
            <button
              aria-label={t('accounts.detail.actionsMenu.transfer')}
              className="icon-button"
              disabled={!writable}
              onClick={onAddTransfer}
              title={t('accounts.detail.actionsMenu.transfer')}
              type="button"
            >
              <Icon name="transactions" size={18} />
            </button>
          );
          if (!phone)
            return transfer ? (
              <>
                {primary}
                {transfer}
              </>
            ) : (
              primary
            );
          return (
            <MobileHeaderActions>
              {primary}
              {transferInAccountMenu ? null : transfer}
            </MobileHeaderActions>
          );
        }}
      />
    </HeaderActions>
  );
}

/** Same chrome as the loaded actions, disabled until the account is known. */
export function AccountDetailActionsSkeleton({ tab }: { tab: AccountTab }) {
  const { t } = useTranslation();
  const balanceTab = tab === 'balance';
  // Mirrors the loaded phone layout: on the Compte tab the transfer lives in the three-dot menu.
  const phone = useMediaQuery('(max-width: 767px)');
  const showTransfer = !balanceTab && !(phone && tab === 'account');

  return (
    <HeaderActions>
      <button
        aria-label={t(
          balanceTab
            ? 'accounts.detail.actionsMenu.recordBalance'
            : 'accounts.detail.actions.addTransaction',
        )}
        className="primary-action"
        disabled
        type="button"
      >
        <Icon name={balanceTab ? 'balance' : 'add'} size={18} />
        <span>
          {t(
            balanceTab
              ? 'accounts.detail.actionsMenu.recordBalance'
              : 'accounts.detail.actions.addTransaction',
          )}
        </span>
      </button>
      {showTransfer ? (
        <button
          aria-label={t('accounts.detail.actionsMenu.transfer')}
          className="icon-button"
          disabled
          title={t('accounts.detail.actionsMenu.transfer')}
          type="button"
        >
          <Icon name="transactions" size={18} />
        </button>
      ) : null}
      {tab === 'account' ? <AccountActionsMenu onRules={() => undefined} /> : null}
    </HeaderActions>
  );
}
