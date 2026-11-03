import { BulkSelectionToolbar } from '@/features/transactions/bulk-selection/BulkSelectionToolbar';
import { AccountOperationFilters } from './account-operation-filters/AccountOperationFilters';
import { emptyMovementFilters, monthBounds } from './account-movements/accountMovementFilters';
import { AccountNavigation, activeAccountTab } from './AccountNavigation';
import { BalanceTabSkeleton } from './balance-tab/BalanceTabSkeleton';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { AccountDetailActionsSkeleton } from './account-detail-actions/AccountDetailActions';
import { AccountIdentityCardSkeleton } from './account-identity/AccountIdentityCardSkeleton';
import { AccountMovementsSectionSkeleton } from './account-movements-section/AccountMovementsSectionSkeleton';
import type { Account } from '@cadran/api-client';
import { AccountHistoryPanelSkeleton } from './account-history/AccountHistoryPanelSkeleton';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import styles from './AccountDetailPage.module.css';

/** Mirrors AccountDetailPage element for element: only account values are pending. */
export function AccountDetailPageSkeleton({
  status,
  accountId = '',
  account = null,
}: {
  status: string;
  accountId?: string;
  account?: Account | null;
}) {
  const tab = activeAccountTab();

  return (
    <div aria-busy="true" className={styles.page} data-testid="account-detail-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <AccountNavigation accountId={accountId} tab={tab} />
      <AccountDetailActionsSkeleton />
      {tab === 'operations' ? (
        <>
          <AccountOperationFilters
            disabled
            actions={
              <BulkSelectionToolbar active={false} bulk={null} inline onToggleActive={() => {}} />
            }
            value={{
              ...emptyMovementFilters,
              ...monthBounds(new URLSearchParams(window.location.search).get('month')),
            }}
            onChange={() => undefined}
          />
          <AccountMovementsSectionSkeleton
            showBulkToolbar={false}
            month={new URLSearchParams(window.location.search).get('month')}
          />
        </>
      ) : tab === 'balance' ? (
        <BalanceTabSkeleton />
      ) : (
        <>
          <AccountIdentityCardSkeleton />
          {account === null || !valuedByPortfolio(account) ? (
            <AccountHistoryPanelSkeleton account={account} />
          ) : null}
        </>
      )}
    </div>
  );
}
