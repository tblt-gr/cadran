import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import splitStyles from '@/features/transactions/split-editor/SplitEditor.module.css';
import styles from './RefundForm.module.css';

const FIELDS = [
  'transactions.fields.account',
  'transactions.fields.amount',
  'transactions.fields.bookedOn',
  'transactions.fields.label',
] as const;

/** Mirrors RefundForm element for element: labels and submit are real, the proposal pending. */
export function RefundFormSkeleton() {
  const { t } = useTranslation();
  return (
    <form aria-busy="true" className={styles.form} data-testid="refund-form-skeleton">
      <SkeletonStatus>{t('transactions.refund.loading')}</SkeletonStatus>
      <p className={styles.remaining}>
        <span>{t('transactions.refund.remaining')}</span>
        <strong>
          <SkeletonBlock className={styles.skeletonAmount} />
        </strong>
      </p>
      <FormGrid>
        {FIELDS.map((key) => (
          <label key={key}>
            <span>{t(key)}</span>
            <SkeletonBlock className={styles.skeletonControl} />
          </label>
        ))}
        <FormSection onToggle={() => {}} open={false} title={t('transactions.form.advanced')}>
          <label>
            <span>{t('transactions.fields.counterparty')}</span>
            <input disabled />
          </label>
          <label>
            <span>{t('transactions.fields.note')}</span>
            <input disabled />
          </label>
        </FormSection>
        <div data-span="full">
          <p>{t('transactions.refund.allocation')}</p>
          <div className={splitStyles.editor}>
            <div className={splitStyles.remaining}>
              <span>{t('transactions.split.remaining')}</span>
              <strong>
                <SkeletonBlock className={styles.skeletonAmount} />
              </strong>
            </div>
            <ol className={splitStyles.rows} />
            <div className={splitStyles.actions}>
              <button className="secondary-action" disabled type="button">
                {t('transactions.split.addRow')}
              </button>
              <button className="secondary-action" disabled type="button">
                {t('transactions.split.splitEvenly')}
              </button>
            </div>
            <p className={splitStyles.hint}>{t('transactions.split.splitEvenlyUnavailable')}</p>
          </div>
        </div>
      </FormGrid>
      <ModalActions>
        <button className="primary-action" disabled type="button">
          {t('transactions.refund.save')}
        </button>
      </ModalActions>
    </form>
  );
}
