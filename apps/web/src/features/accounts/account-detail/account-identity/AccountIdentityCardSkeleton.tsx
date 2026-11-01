import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
import { GroupMarker } from '@/features/accounts/group-marker/GroupMarker';
import styles from './AccountIdentityCard.module.css';
import skeletonStyles from './AccountIdentityCardSkeleton.module.css';

/** Mirrors AccountIdentityCard element for element: only the account values are blocks. */
export function AccountIdentityCardSkeleton({ portfolio = false }: { portfolio?: boolean }) {
  const { t } = useTranslation();
  return (
    <div className={`${portfolio ? styles.embedded : 'card'} ${styles.card}`}>
      <div className={styles.top}>
        <div className={styles.identity}>
          <div className={styles.nameRow}>
            <SkeletonBlock className={skeletonStyles.name} />
          </div>
          <span className={styles.meta}>
            <SkeletonBlock className={skeletonStyles.line} />
          </span>
          <span className={styles.meta}>
            <GroupMarker color={null} pending />
            <SkeletonBlock className={skeletonStyles.line} />
          </span>
        </div>
        {!portfolio ? (
          <div className={styles.balancePanel}>
            <div className={styles.balanceStat}>
              <span className={styles.balanceLabel}>{t('accounts.fields.balance')}</span>
              <AccountValuationCell size="lg" valuation={null} />
            </div>
          </div>
        ) : null}
      </div>
    </div>
  );
}
