import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './EmergencyFund.module.css';
import skeletonStyles from './EmergencyFundCardSkeleton.module.css';

const FACTS = [
  'goals.emergencyFund.liquid',
  'goals.emergencyFund.required',
  'goals.emergencyFund.gap',
] as const;

/** Heading, buttons and fact labels are static and real; only the coverage figures are pending. */
export function EmergencyFundCardSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <section
      aria-busy="true"
      className={`card ${styles.card}`}
      data-testid="emergency-fund-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.heading}>
        <div>
          <p className={styles.eyebrow}>{t('goals.emergencyFund.eyebrow')}</p>
          <h3>{t('goals.emergencyFund.title')}</h3>
        </div>
        <div className={styles.actions}>
          <button className="secondary-action" disabled type="button">
            {t('goals.emergencyFund.explain')}
          </button>
          <button className="secondary-action" disabled type="button">
            {t('goals.emergencyFund.editPolicy')}
          </button>
        </div>
      </div>
      <SkeletonBlock className={skeletonStyles.covered} />
      <SkeletonBlock className={skeletonStyles.meter} />
      <dl className={styles.facts}>
        {FACTS.map((fact) => (
          <div key={fact}>
            <dt>{t(fact)}</dt>
            <dd>
              <SkeletonBlock className={skeletonStyles.fact} />
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
