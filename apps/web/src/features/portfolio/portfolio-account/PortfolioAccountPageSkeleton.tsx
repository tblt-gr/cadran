import { OperationFilters } from './operations/operation-filters/OperationFilters';
import { noFilters } from './operations/operation-filters/operationFilterValues';
import { OperationsTableSkeleton } from './operations/OperationsTableSkeleton';
import { CashTabSkeleton } from '@/features/portfolio/cash/CashTabSkeleton';
import { PositionsTabSkeleton } from './positions/PositionsTabSkeleton';
import { PortfolioAccountHeader } from './portfolio-account-header/PortfolioAccountHeader';
import { activeTab } from './portfolio-tabs/activeTab';
import { PortfolioTabs } from './portfolio-tabs/PortfolioTabs';
import styles from './PortfolioAccountPage.module.css';

/** The final layout with its static chrome real: only the account and table values are blocks. */
export function PortfolioAccountPageSkeleton({
  accountId,
  status,
}: {
  accountId: string;
  status: string;
}) {
  const active = activeTab();
  return (
    <div className={styles.page}>
      <PortfolioAccountHeader accountId={accountId} onCreate={() => undefined} />
      <PortfolioTabs accountId={accountId} active={active} />
      {active === 'operations' ? (
        <>
          <OperationFilters currencyCode="" disabled onChange={() => undefined} value={noFilters} />
          <OperationsTableSkeleton status={status} />
        </>
      ) : active === 'cash' ? (
        <CashTabSkeleton />
      ) : (
        <PositionsTabSkeleton status={status} />
      )}
    </div>
  );
}
