import { BulkSelectionToolbar } from '@/features/transactions/bulk-selection/BulkSelectionToolbar';
import { AccountOperationFilters } from './account-operation-filters/AccountOperationFilters';
import { emptyMovementFilters, monthBounds } from './account-movements/accountMovementFilters';
import { AccountNavigation, accountOperationsActive } from './AccountNavigation';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { AccountDetailActionsSkeleton } from './account-detail-actions/AccountDetailActions';
import { AccountIdentityCardSkeleton } from './account-identity/AccountIdentityCardSkeleton';
import { AccountMovementsSectionSkeleton } from './account-movements-section/AccountMovementsSectionSkeleton';
import styles from './AccountDetailPage.module.css';

/** Mirrors AccountDetailPage element for element: only account values are pending. */
export function AccountDetailPageSkeleton({
  status,
  accountId = '',
}: {
  status: string;
  accountId?: string;
}) {
  return (
    <div aria-busy="true" className={styles.page} data-testid="account-detail-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <AccountNavigation accountId={accountId} operations={accountOperationsActive()} />
      <AccountDetailActionsSkeleton />
      {accountOperationsActive() ? (
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
      ) : (
        <AccountIdentityCardSkeleton />
      )}
    </div>
  );
}
