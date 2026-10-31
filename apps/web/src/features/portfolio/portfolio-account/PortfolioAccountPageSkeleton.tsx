import { ContractTabSkeleton } from '@/features/portfolio/life-insurance/contract-tab/ContractTabSkeleton';
import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
import { ValueHistoryCardSkeleton } from './value-history-card/ValueHistoryCardSkeleton';
import { PortfolioOverview } from './portfolio-overview/PortfolioOverview';
import { OperationFilters } from './operations/operation-filters/OperationFilters';
import { noFilters } from './operations/operation-filters/operationFilterValues';
import { OperationsTableSkeleton } from './operations/OperationsTableSkeleton';
import operationsStyles from './operations/OperationsTab.module.css';
import { CashTabSkeleton } from '@/features/portfolio/cash/CashTabSkeleton';
import { PerformanceTabSkeleton } from '@/features/portfolio/performance/performance-tab/PerformanceTabSkeleton';
import { performanceYear } from '@/features/portfolio/performance/performance-tab/performanceYear';
import { ValuationsTabSkeleton } from '@/features/portfolio/valuations/ValuationsTabSkeleton';
import { AccountTabSkeleton } from './account-tab/AccountTabSkeleton';
import { HoldingsTab } from './holdings-tab/HoldingsTab';
import { PortfolioAccountHeader } from './portfolio-account-header/PortfolioAccountHeader';
import { activeTab } from './portfolio-tabs/activeTab';
import { PortfolioTabs } from './portfolio-tabs/PortfolioTabs';
import styles from './PortfolioAccountPage.module.css';

/** The final layout with its static chrome real: only the account and table values are blocks. */
export function PortfolioAccountPageSkeleton({
  accountId,
  status,
  asOf,
  today,
}: {
  accountId: string;
  status: string;
  asOf: string;
  today: string;
}) {
  const active = activeTab();
  return (
    <div className={styles.page}>
      <PortfolioTabs
        accountId={accountId}
        active={active}
        asOf={asOf}
        kind={active === 'contract' ? 'INSURANCE_CONTRACT' : undefined}
        tools={
          active !== 'operations' && active !== 'performance' ? (
            <fieldset className={styles.frozenField} disabled>
              <BusinessDateField
                compact
                hint={null}
                label="Situation au"
                onChange={() => undefined}
                today={today}
                value={asOf}
              />
            </fieldset>
          ) : null
        }
      />
      <PortfolioAccountHeader
        accountId={accountId}
        onCreate={() => undefined}
        onRecordValuation={() => undefined}
      />
      {active === 'account' ? (
        <AccountTabSkeleton>
          <PortfolioOverview embedded accountId={accountId} asOf={asOf} frozen history={null} />
        </AccountTabSkeleton>
      ) : null}
      {active === 'account' ? <ValueHistoryCardSkeleton /> : null}
      {active === 'contract' ? (
        <AccountTabSkeleton>
          <ContractTabSkeleton />
        </AccountTabSkeleton>
      ) : active === 'account' ? null : active === 'operations' ? (
        <div className={operationsStyles.tab}>
          <OperationFilters currencyCode="" disabled onChange={() => undefined} value={noFilters} />
          <OperationsTableSkeleton status={status} />
        </div>
      ) : active === 'cash' ? (
        <CashTabSkeleton asOf={asOf} />
      ) : active === 'valuations' ? (
        <ValuationsTabSkeleton status={status} />
      ) : active === 'performance' ? (
        <PerformanceTabSkeleton accountId={accountId} year={performanceYear(asOf)} />
      ) : (
        <HoldingsTab accountId={accountId} asOf={asOf} frozen status={status} />
      )}
    </div>
  );
}
