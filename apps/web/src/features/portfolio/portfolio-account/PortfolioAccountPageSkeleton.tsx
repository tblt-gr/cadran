import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
import { ValueHistoryCardSkeleton } from './value-history-card/ValueHistoryCardSkeleton';
import { PortfolioOverview } from './portfolio-overview/PortfolioOverview';
import { OperationFilters } from './operations/operation-filters/OperationFilters';
import { noFilters } from './operations/operation-filters/operationFilterValues';
import { OperationsTableSkeleton } from './operations/OperationsTableSkeleton';
import { CashTabSkeleton } from '@/features/portfolio/cash/CashTabSkeleton';
import { PerformanceTabSkeleton } from '@/features/portfolio/performance/performance-tab/PerformanceTabSkeleton';
import { performanceYear } from '@/features/portfolio/performance/performance-tab/performanceYear';
import { ValuationsTabSkeleton } from '@/features/portfolio/valuations/ValuationsTabSkeleton';
import { PositionsTabSkeleton } from './positions/PositionsTabSkeleton';
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
      <PortfolioAccountHeader accountId={accountId} onCreate={() => undefined} />
      <fieldset className={styles.frozenField} disabled>
        <BusinessDateField
          hint={null}
          label="Au"
          onChange={() => undefined}
          today={today}
          value={asOf}
        />
      </fieldset>
      <PortfolioOverview
        accountId={accountId}
        asOf={asOf}
        frozen
        history={<ValueHistoryCardSkeleton />}
      />
      <PortfolioTabs accountId={accountId} active={active} asOf={asOf} />
      {active === 'operations' ? (
        <>
          <OperationFilters currencyCode="" disabled onChange={() => undefined} value={noFilters} />
          <OperationsTableSkeleton status={status} />
        </>
      ) : active === 'cash' ? (
        <CashTabSkeleton asOf={asOf} />
      ) : active === 'valuations' ? (
        <ValuationsTabSkeleton status={status} />
      ) : active === 'performance' ? (
        <PerformanceTabSkeleton year={performanceYear(asOf)} />
      ) : (
        <PositionsTabSkeleton status={status} />
      )}
    </div>
  );
}
