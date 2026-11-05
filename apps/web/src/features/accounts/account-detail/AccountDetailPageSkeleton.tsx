import { MonthStepper } from '@/features/transactions/month-stepper/MonthStepper';
import { decodeFilters } from '@/features/transactions/transaction-filters/filterState';
import { TransactionFilters } from '@/features/transactions/transaction-filters/TransactionFilters';
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
  const filters = decodeFilters(new URLSearchParams(window.location.search));

  return (
    <div aria-busy="true" className={styles.page} data-testid="account-detail-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <AccountNavigation accountId={accountId} tab={tab} />
      <AccountDetailActionsSkeleton />
      {tab === 'operations' ? (
        <TransactionFilters
          accountLocked
          accounts={account === null ? [] : [account]}
          disabled
          filters={filters}
          onChange={() => undefined}
          onReset={() => undefined}
          revision={0}
        >
          {filters.period === 'month' ? (
            <MonthStepper disabled month={filters.month} onStep={() => undefined} />
          ) : null}
          <AccountMovementsSectionSkeleton />
        </TransactionFilters>
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
