import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { AccountIdentityCardSkeleton } from '@/features/accounts/account-detail/account-identity/AccountIdentityCardSkeleton';
import { AccountMovementsSectionSkeleton } from '@/features/accounts/account-detail/account-movements-section/AccountMovementsSectionSkeleton';
import styles from './AccountTab.module.css';

/** Mirrors AccountTab element for element: its actions disabled, only account values pending. */
export function AccountTabSkeleton({ children }: { children?: ReactNode }) {
  const { t } = useTranslation();
  return (
    <div className={styles.tab}>
      {children}
      <div className={styles.actions}>
        <button className="secondary-action" disabled type="button">
          <Icon name="sliders" size={18} />
          {t('accounts.detail.actions.rules')}
        </button>
        <button className="secondary-action" disabled type="button">
          <Icon name="transfer" size={18} />
          {t('accounts.detail.actions.addTransfer')}
        </button>
      </div>
      <AccountIdentityCardSkeleton />
      <AccountMovementsSectionSkeleton />
    </div>
  );
}
