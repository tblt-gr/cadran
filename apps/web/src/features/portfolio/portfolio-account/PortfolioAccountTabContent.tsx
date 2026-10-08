import type { Account } from '@cadran/api-client';
import type { RefObject } from 'react';
import { ContractTab } from '@/features/portfolio/life-insurance/contract-tab/ContractTab';
import { LifeInsuranceAllocationTab } from '@/features/portfolio/life-insurance/report-tabs/LifeInsuranceAllocationTab';
import { LifeInsuranceSupportsTab } from '@/features/portfolio/life-insurance/report-tabs/LifeInsuranceSupportsTab';
import { LifeInsuranceValuationsTab } from '@/features/portfolio/life-insurance/report-tabs/LifeInsuranceValuationsTab';
import { AllocationTab } from './allocation-tab/AllocationTab';
import { PositionsTab } from './positions/PositionsTab';
import { CashTab } from '@/features/portfolio/cash/CashTab';
import { ValuationsTab } from '@/features/portfolio/valuations/ValuationsTab';
import { PerformanceTab } from '@/features/portfolio/performance/performance-tab/PerformanceTab';
import { OperationsTab } from './operations/OperationsTab';
import type { PortfolioTab } from './portfolio-tabs/PortfolioTabs';
export function PortfolioAccountTabContent({
  account,
  active,
  asOf,
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
  sourceReturnFocus: RefObject<HTMLElement | null>;
  contractRequired: boolean;
  onAsOfChange: (date: string) => void;
  creating: boolean;
  onCloseCreate: () => void;
  onCreate: () => void;
}) {
  const insurance = account.kind === 'INSURANCE_CONTRACT';
  const reporting = { accountId: account.id, asOf, sourceReturnFocus };
  if (active === 'contract' && insurance)
    return (
      <ContractTab
        {...reporting}
        assetCode={account.assetCode}
        writable={account.status === 'ACTIVE'}
      />
    );
  if (active === 'positions')
    return insurance ? (
      <LifeInsuranceSupportsTab {...reporting} />
    ) : (
      <PositionsTab accountId={account.id} asOf={asOf} />
    );
  if (active === 'allocation')
    return insurance ? (
      <LifeInsuranceAllocationTab {...reporting} />
    ) : (
      <AllocationTab accountId={account.id} asOf={asOf} />
    );
  if (active === 'valuations')
    return insurance ? (
      <LifeInsuranceValuationsTab {...reporting} account={account} />
    ) : (
      <ValuationsTab account={account} asOf={asOf} sourceReturnFocus={sourceReturnFocus} />
    );
  if (active === 'cash')
    return <CashTab accountId={account.id} asOf={asOf} onAsOfChange={onAsOfChange} />;
  if (active === 'performance') return <PerformanceTab accountId={account.id} asOf={asOf} />;
  return (
    <OperationsTab
      account={account}
      contractRequired={contractRequired}
      asOf={asOf}
      sourceReturnFocus={sourceReturnFocus}
      creating={creating}
      onCloseCreate={onCloseCreate}
      onCreate={onCreate}
    />
  );
}
