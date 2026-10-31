import type { Account } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { AccountIdentityCard } from '@/features/accounts/account-detail/account-identity/AccountIdentityCard';
import styles from './AccountTab.module.css';

export function AccountTab({
  account,
  children,
}: {
  account: Account;
  today: string;
  children?: ReactNode;
}) {
  return (
    <div className={`card ${styles.tab}`}>
      <AccountIdentityCard account={account} portfolio />
      {children}
    </div>
  );
}
