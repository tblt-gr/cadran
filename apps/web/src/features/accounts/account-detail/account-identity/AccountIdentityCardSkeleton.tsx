import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
import { GroupMarker } from '@/features/accounts/group-marker/GroupMarker';
import styles from './AccountIdentityCard.module.css';
import skeletonStyles from './AccountIdentityCardSkeleton.module.css';

/** Mirrors AccountIdentityCard element for element: only the account values are blocks. */
export function AccountIdentityCardSkeleton() {
  const { t } = useTranslation();
  return (
    <div className={`card ${styles.card}`}>
      <div className={styles.top}>
        <div className={styles.identity}>
          <p>{t('accounts.detail.identity.title')}</p>
          <div className={styles.nameRow}>
            <SkeletonBlock className={skeletonStyles.name} />
          </div>
          <span>
            <SkeletonBlock className={skeletonStyles.line} />
          </span>
          <span className={styles.meta}>
            <SkeletonBlock className={skeletonStyles.line} />
            <GroupMarker color={null} pending />
            <SkeletonBlock className={skeletonStyles.line} />
          </span>
        </div>
        <div className={styles.balancePanel}>
          <span className={styles.balanceLabel}>{t('accounts.fields.balance')}</span>
          <AccountValuationCell size="lg" valuation={null} />
          <span className={styles.openedOn}>
            <SkeletonBlock className={skeletonStyles.line} />
          </span>
        </div>
      </div>
    </div>
  );
}
