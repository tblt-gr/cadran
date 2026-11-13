import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountRulesModals } from '@/features/accounts/account-rules/AccountRulesModals';
import type { Account } from '@cadran/api-client';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { AccountActionsMenu } from '@/features/accounts/account-actions-menu/AccountActionsMenu';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './PortfolioAccountHeader.module.css';

/**
 * Shared shell actions stay available while tabs change: the primary action and the shared
 * three-dot menu. The primary action is the operation, except on the Valorisations tab where it
 * is the valuation and the operation moves into the menu. Pending account actions are disabled.
 */
export function PortfolioAccountHeader({
  account,
  accountId,
  onCreate,
  onRecordValuation,
  onTakeover,
  contractRequired = false,
  operationBlocked = false,
  valuationPrimary = false,
}: {
  account?: Account;
  accountId: string;
  onCreate: () => void;
  onRecordValuation: () => void;
  /** Undefined unless the account is eligible to take over its positions. */
  onTakeover?: () => void;
  contractRequired?: boolean;
  operationBlocked?: boolean;
  /** The Valorisations tab is shown: recording a valuation is the primary action. */
  valuationPrimary?: boolean;
}) {
  const { t } = useTranslation();
  const [rulesOpen, setRulesOpen] = useState(false);
  const writable =
    account !== undefined && account.status === 'ACTIVE' && valuedByPortfolio(account);
  const primaryLabel = t(
    valuationPrimary
      ? 'accounts.detail.actionsMenu.valuation'
      : 'accounts.detail.actions.addTransaction',
  );
  const contractHref = `/portfolios/${accountId}?tab=contract`;

  return (
    <>
      {rulesOpen && account ? (
        <AccountRulesModals
          account={account}
          close={() => setRulesOpen(false)}
          onOverrideRecorded={() => undefined}
          onOverrideWithdrawn={() => undefined}
        />
      ) : null}
      <HeaderActions>
        <button
          aria-label={primaryLabel}
          className={`primary-action ${styles.add}`}
          disabled={
            valuationPrimary ? !writable : !writable || contractRequired || operationBlocked
          }
          onClick={valuationPrimary ? onRecordValuation : onCreate}
          type="button"
        >
          <Icon name={valuationPrimary ? 'balance' : 'add'} size={18} />
          <span className={styles.addLabel}>{primaryLabel}</span>
        </button>
        <AccountActionsMenu
          account={account}
          onAddOperation={
            valuationPrimary && !contractRequired && !operationBlocked ? onCreate : undefined
          }
          onRecordValuation={valuationPrimary ? undefined : onRecordValuation}
          onTakeover={onTakeover}
          onRules={() => setRulesOpen(true)}
        />
      </HeaderActions>
      {contractRequired ? (
        <a href={contractHref} onClick={(event) => handleClientNavigation(event, contractHref)}>
          {t('accounts.detail.actionsMenu.contractFirst')}
        </a>
      ) : null}
    </>
  );
}
