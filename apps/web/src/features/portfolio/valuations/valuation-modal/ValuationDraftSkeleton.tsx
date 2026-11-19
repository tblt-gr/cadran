import { useTranslation } from 'react-i18next';
import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { ValuationCashHint } from './ValuationCashHint';
import { FormField } from './FormField';
import styles from './ValuationForm.module.css';

/**
 * Mirrors ValuationForm in its default "Valeur totale" mode element for
 * element: labels, mode choice and submit are real, every draft value pending.
 */
export function ValuationDraftSkeleton({ loading = true }: { loading?: boolean }) {
  const { t } = useTranslation();
  return (
    <ModalForm
      aria-busy={loading || undefined}
      className={styles.form}
      onSubmit={(event) => event.preventDefault()}
      data-testid="valuation-draft-skeleton"
    >
      {loading ? (
        <SkeletonStatus>{t('portfolio.valuations.form.loadingDraft')}</SkeletonStatus>
      ) : null}
      <fieldset className={styles.segmented} disabled>
        <legend>{t('portfolio.valuations.form.mode')}</legend>
        <label className={styles.segment}>
          <input name="valuation-detail-skeleton" type="radio" />
          {t('portfolio.valuations.form.modeTotal')}
        </label>
        <label className={styles.segment}>
          <input name="valuation-detail-skeleton" type="radio" />
          {t('portfolio.valuations.form.modePositions')}
        </label>
      </fieldset>
      <FormGrid>
        <FormField label={t('portfolio.valuations.form.accountTotal')}>
          {() => <SkeletonBlock className={styles.skeletonControl} />}
        </FormField>
        <FormField
          label={t('portfolio.valuations.form.statementCash')}
          hint={<ValuationCashHint />}
        >
          {() => <SkeletonBlock className={styles.skeletonControl} />}
        </FormField>
        <FormSection
          title={t('portfolio.lifeInsurance.advancedFields')}
          open={false}
          onToggle={() => undefined}
        >
          <FormField label={t('portfolio.valuations.common.note')} wide>
            {() => <SkeletonBlock className={styles.skeletonControl} />}
          </FormField>
        </FormSection>
      </FormGrid>
      <ModalActions>
        <button className="primary-action" disabled type="button">
          {t('portfolio.lifeInsurance.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
