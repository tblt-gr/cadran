import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountRulesModals } from '@/features/accounts/account-rules/AccountRulesModals';
import type { Account } from '@cadran/api-client';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { AccountActionsMenu } from '@/features/accounts/account-actions-menu/AccountActionsMenu';
import { INSTRUMENTS_HREF } from '@/features/accounts/account-actions-menu/accountMenuItems';
import type { PortfolioTab } from '@/features/portfolio/portfolio-account/portfolio-tabs/PortfolioTabs';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { handleClientNavigation, navigateFromPage } from '@/hooks/use-client-navigation';

/**
 * The header actions follow the tab, and only the Compte tab carries the three-dot menu:
 * - Compte: the operation, the instruments and the menu with every other action;
 * - Opérations: the operation, the instruments and the voided-operations toggle;
 * - Valorisations: the valuation and the instruments;
 * - Positions: the instruments and the closed-positions toggle;
 * - Performance, Contrat: none.
 * Pending account actions are disabled.
 */
export function PortfolioAccountHeader({
  account,
  accountId,
  onCreate,
  onRecordValuation,
  onTakeover,
  contractRequired = false,
  operationBlocked = false,
  tab,
  closed,
  voided,
}: {
  account?: Account;
  accountId: string;
  onCreate: () => void;
  onRecordValuation: () => void;
  /** Undefined unless the account is eligible to take over its positions. */
  onTakeover?: () => void;
  contractRequired?: boolean;
  operationBlocked?: boolean;
  tab: PortfolioTab;
  /** The toggle that adds the closed positions to the Positions tab. */
  closed?: { active: boolean; onToggle: () => void };
  /** The toggle that adds the voided operations to the Opérations tab. */
  voided?: { active: boolean; onToggle: () => void };
}) {
  const { t } = useTranslation();
  const [rulesOpen, setRulesOpen] = useState(false);
  const writable =
    account !== undefined && account.status === 'ACTIVE' && valuedByPortfolio(account);
  const valuationPrimary = tab === 'valuations';
  const showsOperation = tab === 'account' || tab === 'operations' || tab === 'cash';
  const showsPrimary = showsOperation || valuationPrimary;
  const showsInstruments =
    tab === 'account' || tab === 'operations' || tab === 'valuations' || tab === 'positions';
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
        {showsPrimary ? (
          <button
            aria-label={primaryLabel}
            className={`primary-action`}
            disabled={
              valuationPrimary ? !writable : !writable || contractRequired || operationBlocked
            }
            onClick={valuationPrimary ? onRecordValuation : onCreate}
            type="button"
          >
            <Icon name={valuationPrimary ? 'balance' : 'add'} size={18} />
            <span>{primaryLabel}</span>
          </button>
        ) : null}
        {showsInstruments ? (
          <a
            aria-label={t('accounts.detail.actionsMenu.instruments')}
            className={`secondary-action`}
            href={INSTRUMENTS_HREF}
            onClick={(event) => {
              event.preventDefault();
              navigateFromPage(INSTRUMENTS_HREF);
            }}
          >
            <Icon name="chart-line" size={18} />
            <span>{t('accounts.detail.actionsMenu.instruments')}</span>
          </a>
        ) : null}
        {voided && tab === 'operations' ? (
          <button
            aria-label="Afficher les opérations annulées"
            aria-pressed={voided.active}
            className="icon-button"
            onClick={voided.onToggle}
            title="Afficher les opérations annulées"
            type="button"
          >
            <Icon name={voided.active ? 'eye' : 'eye-off'} size={18} />
          </button>
        ) : null}
        {closed && tab === 'positions' && account?.kind !== 'INSURANCE_CONTRACT' ? (
          <button
            aria-label="Afficher les positions soldées"
            aria-pressed={closed.active}
            className="icon-button"
            onClick={closed.onToggle}
            title="Afficher les positions soldées"
            type="button"
          >
            <Icon name={closed.active ? 'eye' : 'eye-off'} size={18} />
          </button>
        ) : null}
        {tab === 'account' ? (
          <AccountActionsMenu
            account={account}
            onRecordValuation={onRecordValuation}
            onTakeover={onTakeover}
            onRules={() => setRulesOpen(true)}
          />
        ) : null}
      </HeaderActions>
      {contractRequired ? (
        <a href={contractHref} onClick={(event) => handleClientNavigation(event, contractHref)}>
          {t('accounts.detail.actionsMenu.contractFirst')}
        </a>
      ) : null}
    </>
  );
}
