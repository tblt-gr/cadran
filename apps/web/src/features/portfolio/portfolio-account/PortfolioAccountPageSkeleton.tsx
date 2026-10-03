import { OperationFilters } from './operations/operation-filters/OperationFilters';
import { noFilters } from './operations/operation-filters/operationFilterValues';
import { OperationsTableSkeleton } from './operations/OperationsTableSkeleton';
import { PositionsTabSkeleton } from './positions/PositionsTabSkeleton';
import { PortfolioAccountHeader } from './portfolio-account-header/PortfolioAccountHeader';
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
  const active =
    new URLSearchParams(window.location.search).get('tab') === 'operations'
      ? 'operations'
      : 'positions';
  return (
    <div className={styles.page}>
      <PortfolioAccountHeader accountId={accountId} onCreate={() => undefined} />
      <PortfolioTabs accountId={accountId} active={active} />
      {active === 'operations' ? (
        <>
          <OperationFilters currencyCode="" disabled onChange={() => undefined} value={noFilters} />
          <OperationsTableSkeleton status={status} />
        </>
      ) : (
        <PositionsTabSkeleton status={status} />
      )}
    </div>
  );
}
