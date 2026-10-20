import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
import { AccountDetailActionsSkeleton } from './account-detail-actions/AccountDetailActions';
import identityStyles from './account-identity/AccountIdentityCard.module.css';
import { AccountMovementsTableSkeleton } from './account-movements/AccountMovementsTableSkeleton';
import sectionStyles from './account-movements-section/AccountMovementsSection.module.css';
import styles from './AccountDetailPage.module.css';
import skeletonStyles from './AccountDetailPageSkeleton.module.css';

/** Mirrors AccountDetailPage element for element: only account values are pending. */
export function AccountDetailPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.page} data-testid="account-detail-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <AccountDetailActionsSkeleton />
      <div className={`card ${identityStyles.card}`}>
        <div className={identityStyles.top}>
          <div className={identityStyles.identity}>
            <p>{t('accounts.detail.identity.title')}</p>
            <div className={identityStyles.nameRow}>
              <SkeletonBlock className={skeletonStyles.name} />
            </div>
            <SkeletonBlock className={skeletonStyles.line} />
            <SkeletonBlock className={skeletonStyles.line} />
          </div>
          <div className={identityStyles.balancePanel}>
            <span className={identityStyles.balanceLabel}>{t('accounts.fields.balance')}</span>
            <AccountValuationCell size="lg" valuation={null} />
            <span className={identityStyles.openedOn}>
              <SkeletonBlock className={skeletonStyles.line} />
            </span>
          </div>
        </div>
      </div>
      <div className={sectionStyles.movementsHeading}>
        <h3 className={sectionStyles.movementsTitle}>{t('accounts.detail.movements.title')}</h3>
      </div>
      <AccountMovementsTableSkeleton announce={false} />
    </div>
  );
}
