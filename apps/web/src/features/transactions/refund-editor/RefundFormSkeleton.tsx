import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonField, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './RefundForm.module.css';

const FIELDS = [
  'transactions.fields.account',
  'transactions.fields.amount',
  'transactions.fields.bookedOn',
  'transactions.fields.label',
  'transactions.fields.counterparty',
  'transactions.fields.note',
] as const;

/** Mirrors RefundForm element for element: labels and submit are real, the proposal pending. */
export function RefundFormSkeleton() {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.form} data-testid="refund-form-skeleton">
      <SkeletonStatus>{t('transactions.refund.loading')}</SkeletonStatus>
      <p className={styles.remaining}>
        <span>{t('transactions.refund.remaining')}</span>
        <strong>
          <SkeletonBlock className={styles.skeletonAmount} />
        </strong>
      </p>
      {FIELDS.map((key) => (
        <SkeletonField key={key} label={t(key)} />
      ))}
      <div>
        <p>{t('transactions.refund.allocation')}</p>
        <SkeletonBlock className={styles.skeletonSplits} />
      </div>
      <div className={styles.actions}>
        <button className="primary-action" disabled type="button">
          {t('transactions.refund.save')}
        </button>
      </div>
    </div>
  );
}
