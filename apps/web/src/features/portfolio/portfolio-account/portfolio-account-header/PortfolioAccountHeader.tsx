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
 * Shared shell actions stay available while tabs change: the primary operation and the shared
 * three-dot menu. Pending account actions are disabled.
 */
export function PortfolioAccountHeader({
  account,
  accountId,
  onCreate,
  onRecordValuation,
  contractRequired = false,
  operationBlocked = false,
}: {
  account?: Account;
  accountId: string;
  onCreate: () => void;
  onRecordValuation: () => void;
  contractRequired?: boolean;
  operationBlocked?: boolean;
}) {
  const { t } = useTranslation();
  const [rulesOpen, setRulesOpen] = useState(false);
  const writable =
    account !== undefined && account.status === 'ACTIVE' && valuedByPortfolio(account);
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
          aria-label={t('accounts.detail.actions.addTransaction')}
          className={`primary-action ${styles.add}`}
          disabled={!writable || contractRequired || operationBlocked}
          onClick={onCreate}
          type="button"
        >
          <Icon name="add" size={18} />
          <span className={styles.addLabel}>{t('accounts.detail.actions.addTransaction')}</span>
        </button>
        <AccountActionsMenu
          account={account}
          onRecordValuation={onRecordValuation}
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
