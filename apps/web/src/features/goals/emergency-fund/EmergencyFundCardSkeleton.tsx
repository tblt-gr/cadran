import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './EmergencyFund.module.css';
import skeletonStyles from './EmergencyFundCardSkeleton.module.css';

/** Mirrors EmergencyFundCard element for element: title and buttons are real; figures pending. */
export function EmergencyFundCardSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <article
      aria-busy="true"
      className={`card ${styles.card}`}
      data-testid="emergency-fund-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.heading}>
        <h3>{t('goals.emergencyFund.title')}</h3>
        <div className={styles.actions}>
          <button
            aria-label={t('goals.emergencyFund.explain')}
            className="icon-ghost"
            disabled
            type="button"
          >
            <Icon name="info" size={18} />
          </button>
          <button
            aria-label={t('goals.emergencyFund.editPolicy')}
            className="icon-ghost"
            disabled
            type="button"
          >
            <Icon name="settings" size={18} />
          </button>
        </div>
      </div>
      <div className={styles.position}>
        <p className={styles.amounts}>
          <strong className="money">
            <SkeletonBlock className={skeletonStyles.amount} />
          </strong>
        </p>
        <p className={styles.gap}>
          <SkeletonBlock className={skeletonStyles.fact} />
        </p>
      </div>
      <SkeletonBlock className={skeletonStyles.meter} />
      <p className={styles.coverage}>
        <SkeletonBlock className={skeletonStyles.fact} />
      </p>
    </article>
  );
}
