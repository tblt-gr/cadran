import type { Account } from '@cadran/api-client';
import type { RefObject } from 'react';
import { ContractTab } from '@/features/portfolio/life-insurance/contract-tab/ContractTab';
import { LifeInsuranceSupportsTab } from '@/features/portfolio/life-insurance/report-tabs/LifeInsuranceSupportsTab';
import { LifeInsuranceValuationsTab } from '@/features/portfolio/life-insurance/report-tabs/LifeInsuranceValuationsTab';
import { CashTab } from '@/features/portfolio/cash/CashTab';
import { ValuationsTab } from '@/features/portfolio/valuations/ValuationsTab';
import { PerformanceTab } from '@/features/portfolio/performance/performance-tab/PerformanceTab';
import { AccountTab } from './account-tab/AccountTab';
import { HoldingsTab } from './holdings-tab/HoldingsTab';
import { OperationsTab } from './operations/OperationsTab';
import type { PortfolioTab } from './portfolio-tabs/PortfolioTabs';

/** The creation modals the page header opens: one at a time, on the tab that lists its result. */
export type PortfolioCreation = 'operation' | 'valuation' | null;

export function PortfolioAccountTabContent({
  account,
  active,
  asOf,
  today,
  sourceReturnFocus,
  contractRequired,
  onAsOfChange,
  creating,
  onCloseCreate,
  onCreate,
}: {
  account: Account;
  active: PortfolioTab;
  asOf: string;
  today: string;
  sourceReturnFocus: RefObject<HTMLElement | null>;
  contractRequired: boolean;
  onAsOfChange: (date: string) => void;
  creating: PortfolioCreation;
  onCloseCreate: () => void;
  onCreate: () => void;
}) {
  const insurance = account.kind === 'INSURANCE_CONTRACT';
  const reporting = { accountId: account.id, asOf, sourceReturnFocus };
  if (active === 'account' || active === 'contract')
    return (
      <AccountTab account={account} today={today}>
        {insurance ? (
          <ContractTab
            {...reporting}
            assetCode={account.assetCode}
            writable={account.status === 'ACTIVE'}
          />
        ) : null}
      </AccountTab>
    );
  if (active === 'positions')
    return insurance ? (
      <LifeInsuranceSupportsTab {...reporting} />
    ) : (
      <HoldingsTab accountId={account.id} asOf={asOf} />
    );
  if (active === 'valuations') {
    const recording = {
      recording: creating === 'valuation',
      onRecordClose: onCloseCreate,
    };
    return insurance ? (
      <LifeInsuranceValuationsTab {...reporting} {...recording} account={account} />
    ) : (
      <ValuationsTab
        {...recording}
        account={account}
        asOf={asOf}
        sourceReturnFocus={sourceReturnFocus}
      />
    );
  }
  if (active === 'cash')
    return <CashTab accountId={account.id} asOf={asOf} onAsOfChange={onAsOfChange} />;
  if (active === 'performance') return <PerformanceTab accountId={account.id} asOf={asOf} />;
  return (
    <OperationsTab
      account={account}
      contractRequired={contractRequired}
      asOf={asOf}
      sourceReturnFocus={sourceReturnFocus}
      creating={creating === 'operation'}
      onCloseCreate={onCloseCreate}
      onCreate={onCreate}
    />
  );
}
