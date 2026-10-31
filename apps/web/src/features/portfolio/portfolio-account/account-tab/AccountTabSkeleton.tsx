import type { ReactNode } from 'react';
import { AccountIdentityCardSkeleton } from '@/features/accounts/account-detail/account-identity/AccountIdentityCardSkeleton';
import styles from './AccountTab.module.css';

/** Mirrors AccountTab element for element. */
export function AccountTabSkeleton({ children }: { children?: ReactNode }) {
  return (
    <div className={`card ${styles.tab}`}>
      <AccountIdentityCardSkeleton portfolio />
      {children}
    </div>
  );
}
