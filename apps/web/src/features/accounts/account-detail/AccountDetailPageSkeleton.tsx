import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { AccountDetailActionsSkeleton } from './account-detail-actions/AccountDetailActions';
import { AccountIdentityCardSkeleton } from './account-identity/AccountIdentityCardSkeleton';
import { AccountMovementsSectionSkeleton } from './account-movements-section/AccountMovementsSectionSkeleton';
import styles from './AccountDetailPage.module.css';

/** Mirrors AccountDetailPage element for element: only account values are pending. */
export function AccountDetailPageSkeleton({ status }: { status: string }) {
  return (
    <div aria-busy="true" className={styles.page} data-testid="account-detail-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <AccountDetailActionsSkeleton />
      <AccountIdentityCardSkeleton />
      <AccountMovementsSectionSkeleton />
    </div>
  );
}
