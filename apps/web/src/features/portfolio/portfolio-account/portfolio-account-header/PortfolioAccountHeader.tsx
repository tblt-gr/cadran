import { useState } from 'react';
import { AccountRulesModals } from '@/features/accounts/account-rules/AccountRulesModals';
import type { Account } from '@cadran/api-client';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './PortfolioAccountHeader.module.css';

/** Shared shell actions stay available while tabs change; pending account actions are disabled. */
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
  const [rulesOpen, setRulesOpen] = useState(false);
  const instrumentsHref = '/portfolios/instruments';
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
          aria-label="Nouvelle opération"
          className={`primary-action ${styles.add}`}
          disabled={!writable || contractRequired || operationBlocked}
          onClick={onCreate}
          type="button"
        >
          <Icon name="add" size={18} />
          <span className={styles.addLabel}>Nouvelle opération</span>
        </button>
        <button
          aria-label="Nouvelle valorisation"
          className={`secondary-action ${styles.add}`}
          disabled={!writable}
          onClick={onRecordValuation}
          type="button"
        >
          <Icon name="balance" size={18} />
          <span className={styles.addLabel}>Nouvelle valorisation</span>
        </button>
        <button
          className="icon-button"
          aria-label="Voir les règles"
          title="Voir les règles"
          disabled={!account}
          onClick={() => setRulesOpen(true)}
          type="button"
        >
          <Icon name="sliders" />
        </button>
        <a
          aria-label="Gérer les instruments"
          className="icon-button"
          href={instrumentsHref}
          onClick={(event) => handleClientNavigation(event, instrumentsHref)}
          title="Gérer les instruments"
        >
          <Icon name="chart-line" />
        </a>
      </HeaderActions>
      {contractRequired ? (
        <a href={contractHref} onClick={(event) => handleClientNavigation(event, contractHref)}>
          Renseignez d'abord le contrat
        </a>
      ) : null}
    </>
  );
}
